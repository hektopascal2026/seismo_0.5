<?php

declare(strict_types=1);

namespace Seismo\Repository;

use DateTimeImmutable;
use PDO;
use PDOException;

/**
 * RSS / Substack / scraper rows in `feed_items` + `feeds` metadata.
 * All entry-source SQL goes through entryTable().
 */
final class FeedItemRepository
{
    public const MAX_LIMIT = 200;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Active RSS + Substack feeds (not scraper-only rows).
     *
     * @return list<array<string, mixed>>
     */
    public function listFeedsForRssRefresh(int $limit, int $offset): array
    {
        $limit  = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);
        $sql = 'SELECT * FROM ' . entryTable('feeds') . '
            WHERE disabled = 0
              AND source_type IN (\'rss\', \'substack\')
            ORDER BY id ASC
            LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;

        return $this->selectOrEmpty($sql);
    }

    /**
     * Swiss Parliament press list (`source_type = parl_press`) — one logical
     * feed row; refreshed by {@see \Seismo\Service\CoreRunner::ID_PARL_PRESS}.
     *
     * @return list<array<string, mixed>>
     */
    public function listFeedsForParlPressRefresh(int $limit, int $offset): array
    {
        $limit  = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);
        $sql = 'SELECT * FROM ' . entryTable('feeds') . "
            WHERE disabled = 0
              AND source_type = 'parl_press'
            ORDER BY id ASC
            LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset;

        return $this->selectOrEmpty($sql);
    }

    /**
     * Feeds that should be scraped (explicit scraper type or listed in scraper_configs).
     *
     * @return list<array<string, mixed>>
     */
    public function listFeedsForScraperRefresh(int $limit, int $offset): array
    {
        $limit  = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);
        $feeds = entryTable('feeds');
        $sc    = entryTable('scraper_configs');
        $sql = "SELECT DISTINCT f.* FROM {$feeds} f
            LEFT JOIN {$sc} sc ON sc.url = f.url AND sc.disabled = 0
            WHERE f.disabled = 0
              AND (f.source_type = 'scraper' OR sc.id IS NOT NULL)
            ORDER BY f.id ASC
            LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset;

        return $this->selectOrEmpty($sql);
    }

    /**
     * @param list<array<string, mixed>> $rows Normalised feed item dicts:
     *        guid, title, link, description, content, author, published_date (Y-m-d H:i:s|null)
     */
    public function upsertFeedItems(int $feedId, array $rows): int
    {
        if (isSatellite()) {
            throw new \RuntimeException('FeedItemRepository::upsertFeedItems must not run on a satellite.');
        }
        if ($rows === []) {
            return 0;
        }

        $table = entryTable('feed_items');
        $sql = 'INSERT INTO ' . $table . ' (
            feed_id, guid, title, link, description, content, author,
            published_date, content_hash, hidden, cached_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, UTC_TIMESTAMP()
        )
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            link = VALUES(link),
            description = VALUES(description),
            content = VALUES(content),
            author = VALUES(author),
            published_date = IF(
                VALUES(content_hash) = feed_items.content_hash,
                feed_items.published_date,
                VALUES(published_date)
            ),
            content_hash = VALUES(content_hash),
            cached_at = UTC_TIMESTAMP()';

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare($sql);
            $n = 0;
            foreach ($rows as $row) {
                $guid = (string)($row['guid'] ?? '');
                $title = trim((string)($row['title'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $link = trim((string)($row['link'] ?? ''));
                if ($link === '' || !$this->isNavigableHttpUrl($link)) {
                    continue;
                }
                if ($guid === '') {
                    $guid = substr(sha1($link . "\0" . $title), 0, 32);
                }
                $desc = (string)($row['description'] ?? '');
                $content = (string)($row['content'] ?? '');
                if ($content === '' && $desc !== '') {
                    $content = $desc;
                }
                $pub = $row['published_date'] ?? null;
                $pubStr = null;
                if ($pub instanceof DateTimeImmutable) {
                    $pubStr = $pub->format('Y-m-d H:i:s');
                } elseif (is_string($pub) && $pub !== '') {
                    $pubStr = $pub;
                }
                $hash = (string)($row['content_hash'] ?? '');
                if ($hash === '') {
                    $hash = substr(sha1($link . "\0" . $content), 0, 32);
                }
                $stmt->execute([
                    $feedId,
                    $guid,
                    $title,
                    $link,
                    $desc,
                    $content,
                    (string)($row['author'] ?? ''),
                    $pubStr,
                    $hash,
                ]);
                $n++;
            }
            $this->pdo->commit();

            return $n;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function touchFeedSuccess(int $feedId): void
    {
        if (isSatellite()) {
            return;
        }
        $sql = 'UPDATE ' . entryTable('feeds') . '
            SET last_fetched = UTC_TIMESTAMP(),
                consecutive_failures = 0,
                last_error = NULL,
                last_error_at = NULL
            WHERE id = ?';
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$feedId]);
        } catch (PDOException $e) {
            error_log('FeedItemRepository::touchFeedSuccess: ' . $e->getMessage());
        }
    }

    /**
     * Remove feed_items rows that cannot come from {@see \Seismo\Core\Fetcher\ParlPressFetchService}
     * (guids are always `parl_mm:{slug}`). Same feed_id may contain RSS-shaped junk if the row was
     * ever refreshed as `source_type = rss` against the SharePoint URL — 0.4 stored `Untitled`
     * in that case ({@see cacheFeedItems} in 0.4 controllers/rss.php).
     */
    public function deleteAlienParlPressFeedItems(int $feedId): int
    {
        if (isSatellite()) {
            return 0;
        }
        if ($feedId <= 0) {
            return 0;
        }
        $table = entryTable('feed_items');
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM ' . $table . ' WHERE feed_id = ? AND guid NOT LIKE ?'
            );
            // Default LIKE escape: backslash before _ so only literal parl_mm: prefix matches.
            $stmt->execute([$feedId, 'parl\\_mm:%']);

            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log('FeedItemRepository::deleteAlienParlPressFeedItems: ' . $e->getMessage());

            return 0;
        }
    }

    public function touchFeedFailure(int $feedId, string $message): void
    {
        if (isSatellite()) {
            return;
        }
        $msg = mb_substr($message, 0, 2000);
        $sql = 'UPDATE ' . entryTable('feeds') . '
            SET consecutive_failures = consecutive_failures + 1,
                last_error = ?,
                last_error_at = UTC_TIMESTAMP()
            WHERE id = ?';
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$msg, $feedId]);
        } catch (PDOException $e) {
            error_log('FeedItemRepository::touchFeedFailure: ' . $e->getMessage());
        }
    }

    /**
     * Age column used by both `prune()` and `dryRunPrune()`. `cached_at`
     * is the row-insert timestamp (populated by MariaDB default), which
     * is the honest cutoff for retention — `published_date` comes from
     * the feed publisher and can be arbitrarily old or missing.
     */
    private const AGE_COLUMN = 'cached_at';

    /**
     * Delete feed_items older than `$olderThan` unless protected by a
     * keep-predicate. Honours the pre-Slice-5a invariant that
     * soft-deleted rows (`hidden = 1`) are themselves kept — the
     * soft-delete flag is its own retention signal (admin marked them
     * hidden; don't silently hard-delete without their say).
     *
     * @param list<string> $keepPredicates Tokens from
     *        {@see \Seismo\Service\RetentionService}.
     */
    public function prune(DateTimeImmutable $olderThan, array $keepPredicates): int
    {
        if (isSatellite()) {
            throw new \RuntimeException('FeedItemRepository::prune must not run on a satellite.');
        }

        $cutoff = $olderThan->format('Y-m-d H:i:s');
        $where  = $this->buildPruneWhere($keepPredicates);

        try {
            $stmt = $this->pdo->prepare(
                // Multi-table DELETE form: see EmailRepository::prune for the
                // rationale — alias is required by buildPruneWhere() / the
                // RetentionPredicates fragments, and the single-table DELETE
                // syntax rejects aliases (MariaDB 1064).
                'DELETE t FROM ' . entryTable('feed_items') . ' t WHERE ' . $where
            );
            $stmt->execute([$cutoff]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return 0;
            }
            throw $e;
        }
    }

    /**
     * Dry-run counterpart of `prune()`. Same WHERE clause, `SELECT
     * COUNT(*)` instead of DELETE — the two stay in sync by construction
     * because both go through `buildPruneWhere()`.
     *
     * @param list<string> $keepPredicates
     */
    public function dryRunPrune(DateTimeImmutable $olderThan, array $keepPredicates): int
    {
        $cutoff = $olderThan->format('Y-m-d H:i:s');
        $where  = $this->buildPruneWhere($keepPredicates);

        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ' . entryTable('feed_items') . ' t WHERE ' . $where
            );
            $stmt->execute([$cutoff]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return 0;
            }
            throw $e;
        }
    }

    /**
     * @param list<string> $keepPredicates
     */
    private function buildPruneWhere(array $keepPredicates): string
    {
        $keeps = \Seismo\Service\RetentionPredicates::forEntryType('feed_item', $keepPredicates);
        $where = 't.' . self::AGE_COLUMN . ' < ? AND t.hidden = 0';
        if ($keeps !== '') {
            $where .= ' AND NOT (' . $keeps . ')';
        }
        return $where;
    }

    private function isNavigableHttpUrl(string $url): bool
    {
        $u = trim($url);
        if ($u === '' || $u === '#') {
            return false;
        }

        return (bool)preg_match('#^https?://#i', $u);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function selectOrEmpty(string $sql): array
    {
        try {
            $stmt = $this->pdo->query($sql);
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return [];
            }
            throw $e;
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
