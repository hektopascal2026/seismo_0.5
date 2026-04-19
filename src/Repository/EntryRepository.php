<?php
/**
 * Polymorphic read repository for the unified dashboard timeline.
 *
 * Returns the same wrapper shape that views/partials/dashboard_entry_loop.php
 * has consumed since 0.4:
 *
 *   [
 *     'type'         => 'feed'|'substack'|'scraper'|'email'|'lex'|'calendar',
 *     'entry_type'   => 'feed_item'|'email'|'lex_item'|'calendar_event',
 *     'entry_id'     => int,
 *     'date'         => int (unix timestamp, for sort + day separators),
 *     'data'         => array (raw row from the source table — NOT escaped),
 *     'score'        => ?array (entry_scores row, local DB),
 *     'is_favourite' => bool (entry_favourites presence, local DB),
 *   ]
 *
 * Design rules enforced here:
 *
 *   - Bounded: every read method takes $limit/$offset, hard-capped at
 *     MAX_LIMIT so a runaway `?limit=1000000` URL can't OOM the shared host.
 *   - Satellite-safe: every entry-source table goes through entryTable()
 *     so a satellite reads cross-DB from the mothership. Score and favourite
 *     tables stay local (never wrapped).
 *   - Raw output: rows are returned unescaped, as MariaDB stores them.
 *     Escaping is the view's job (e(), or the highlightSearchTerm helper).
 *   - Resilient: missing entry tables (e.g. calendar_events before Leg is
 *     migrated, no fetched_emails yet) are treated as "no rows" not fatals.
 *     Fatal-hiding is limited to table-missing errors so real schema bugs
 *     still surface.
 */

declare(strict_types=1);

namespace Seismo\Repository;

use PDO;
use PDOException;

final class EntryRepository
{
    /**
     * Hard cap on the final timeline size.
     *
     * Per-source queries each take (limit + offset) rows so merge+sort produces
     * a stable window, which means worst-case memory is roughly
     *   5 families × MAX_LIMIT rows × ~10 KB/row ≈ 10 MB
     * — comfortably under the 128 MB shared-hosting default.
     */
    public const MAX_LIMIT = 200;

    private ?string $cachedEmailTable = null;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Merged newest-first timeline across every entry family.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLatestTimeline(int $limit, int $offset = 0): array
    {
        $limit  = $this->clampLimit($limit);
        $offset = max(0, $offset);

        // Per-source fetch size. Taking $limit + $offset from each source
        // guarantees we have enough rows to slice a valid offset/limit window
        // from the merged result, even when one source dominates the head.
        $perSource = $limit + $offset;

        $items = [];
        foreach ($this->fetchFeedItems($perSource) as $row) {
            $items[] = $this->wrapFeedItem($row);
        }
        foreach ($this->fetchEmails($perSource) as $row) {
            $items[] = $this->wrapEmail($row);
        }
        foreach ($this->fetchLexItems($perSource) as $row) {
            $items[] = $this->wrapLexItem($row);
        }
        foreach ($this->fetchCalendarEvents($perSource) as $row) {
            $items[] = $this->wrapCalendarEvent($row);
        }

        usort($items, static fn ($a, $b) => ($b['date'] ?? 0) <=> ($a['date'] ?? 0));
        $items = array_slice($items, $offset, $limit);

        $this->attachScores($items);
        $this->attachFavourites($items);

        return $items;
    }

    /**
     * Total timeline size approximation (sum of per-family counts).
     * Bounded for the same reason the list is: counts are cheap but we
     * still don't want to scan unbounded partitions on each page load.
     */
    public function countLatestTimelineApprox(): int
    {
        $total = 0;
        $total += $this->countOrZero('SELECT COUNT(*) FROM ' . entryTable('feed_items'));
        $emailTable = $this->resolveEmailTable();
        if ($emailTable !== null) {
            $total += $this->countOrZero('SELECT COUNT(*) FROM ' . entryTable($emailTable));
        }
        $total += $this->countOrZero('SELECT COUNT(*) FROM ' . entryTable('lex_items'));
        $total += $this->countOrZero('SELECT COUNT(*) FROM ' . entryTable('calendar_events'));
        return $total;
    }

    // ------------------------------------------------------------------
    // Per-family fetchers. Each returns raw rows, newest-first, bounded.
    // ------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchFeedItems(int $limit): array
    {
        $sql = '
            SELECT fi.*,
                   f.title       AS feed_title,
                   f.category    AS feed_category,
                   f.source_type AS feed_source_type,
                   f.url         AS feed_url,
                   f.title       AS feed_name
            FROM ' . entryTable('feed_items') . ' fi
            JOIN ' . entryTable('feeds') . ' f ON fi.feed_id = f.id
            WHERE f.disabled = 0
            ORDER BY fi.published_date DESC, fi.cached_at DESC
            LIMIT ' . (int)$limit;
        return $this->selectOrEmpty($sql);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchEmails(int $limit): array
    {
        $table = $this->resolveEmailTable();
        if ($table === null) {
            return [];
        }
        $sql = 'SELECT * FROM ' . entryTable($table) . '
                ORDER BY COALESCE(date_received, date_utc, created_at, date_sent) DESC
                LIMIT ' . (int)$limit;
        return $this->selectOrEmpty($sql);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchLexItems(int $limit): array
    {
        $sql = 'SELECT * FROM ' . entryTable('lex_items') . '
                ORDER BY document_date DESC, created_at DESC
                LIMIT ' . (int)$limit;
        return $this->selectOrEmpty($sql);
    }

    /**
     * Leg / parliamentary business. Future-biased window so upcoming sessions
     * still show up even when older data dominates.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchCalendarEvents(int $limit): array
    {
        $sql = 'SELECT * FROM ' . entryTable('calendar_events') . '
                WHERE event_date IS NULL
                   OR event_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                ORDER BY event_date DESC
                LIMIT ' . (int)$limit;
        return $this->selectOrEmpty($sql);
    }

    // ------------------------------------------------------------------
    // Wrappers — convert a raw row into the dashboard loop's expected shape.
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function wrapFeedItem(array $row): array
    {
        $sourceType = (string)($row['feed_source_type'] ?? '');
        $category   = (string)($row['feed_category']    ?? '');

        if ($sourceType === 'substack') {
            $type = 'substack';
        } elseif ($sourceType === 'scraper' || $category === 'scraper') {
            $type = 'scraper';
        } else {
            $type = 'feed';
        }

        $date = (string)($row['published_date'] ?? $row['cached_at'] ?? '');
        return [
            'type'         => $type,
            'entry_type'   => 'feed_item',
            'entry_id'     => (int)($row['id'] ?? 0),
            'date'         => $date !== '' ? (int)strtotime($date) : 0,
            'data'         => $row,
            'score'        => null,
            'is_favourite' => false,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function wrapEmail(array $row): array
    {
        $date = (string)(
            $row['date_received']
            ?? $row['date_utc']
            ?? $row['created_at']
            ?? $row['date_sent']
            ?? ''
        );
        return [
            'type'         => 'email',
            'entry_type'   => 'email',
            'entry_id'     => (int)($row['id'] ?? 0),
            'date'         => $date !== '' ? (int)strtotime($date) : 0,
            'data'         => $row,
            'score'        => null,
            'is_favourite' => false,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function wrapLexItem(array $row): array
    {
        $date = (string)($row['document_date'] ?? $row['created_at'] ?? '');
        return [
            'type'         => 'lex',
            'entry_type'   => 'lex_item',
            'entry_id'     => (int)($row['id'] ?? 0),
            'date'         => $date !== '' ? (int)strtotime($date) : 0,
            'data'         => $row,
            'score'        => null,
            'is_favourite' => false,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function wrapCalendarEvent(array $row): array
    {
        $date = (string)($row['event_date'] ?? $row['created_at'] ?? '');
        return [
            'type'         => 'calendar',
            'entry_type'   => 'calendar_event',
            'entry_id'     => (int)($row['id'] ?? 0),
            'date'         => $date !== '' ? (int)strtotime($date) : 0,
            'data'         => $row,
            'score'        => null,
            'is_favourite' => false,
        ];
    }

    // ------------------------------------------------------------------
    // Score + favourite joins. Both live in local tables (never wrapped).
    // ------------------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function attachScores(array &$items): void
    {
        if ($items === []) {
            return;
        }
        try {
            $stmt = $this->pdo->query(
                'SELECT entry_type, entry_id, relevance_score, predicted_label,
                        explanation, score_source
                 FROM entry_scores'
            );
            $map = [];
            foreach ($stmt->fetchAll() as $row) {
                $map[$row['entry_type'] . ':' . $row['entry_id']] = $row;
            }
        } catch (PDOException $e) {
            return;
        }
        foreach ($items as &$item) {
            $key = $item['entry_type'] . ':' . $item['entry_id'];
            if (isset($map[$key])) {
                $item['score'] = $map[$key];
            }
        }
        unset($item);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function attachFavourites(array &$items): void
    {
        if ($items === []) {
            return;
        }
        try {
            $stmt = $this->pdo->query(
                'SELECT entry_type, entry_id FROM entry_favourites'
            );
            $map = [];
            foreach ($stmt->fetchAll() as $row) {
                $map[$row['entry_type'] . ':' . $row['entry_id']] = true;
            }
        } catch (PDOException $e) {
            return;
        }
        foreach ($items as &$item) {
            $key = $item['entry_type'] . ':' . $item['entry_id'];
            if (isset($map[$key])) {
                $item['is_favourite'] = true;
            }
        }
        unset($item);
    }

    // ------------------------------------------------------------------
    // Infrastructure.
    // ------------------------------------------------------------------

    /**
     * Resolve which physical email table this deployment uses.
     *
     * 0.4 shipped two tables (`emails`, `fetched_emails`) for historical
     * reasons — the IMAP cron wrote to one, older code wrote to the other.
     * Slice 4 unifies this. Until then, we enumerate the DB once per request
     * and pick whichever table exists. In satellite mode we enumerate the
     * mothership schema.
     *
     * Returns null when no plausible email table is present so the email
     * family quietly drops out of the timeline.
     */
    private function resolveEmailTable(): ?string
    {
        if ($this->cachedEmailTable !== null) {
            return $this->cachedEmailTable === '' ? null : $this->cachedEmailTable;
        }

        $showSql = SEISMO_MOTHERSHIP_DB !== ''
            ? 'SHOW TABLES FROM `' . str_replace('`', '``', (string)SEISMO_MOTHERSHIP_DB) . '`'
            : 'SHOW TABLES';

        try {
            $tables = $this->pdo->query($showSql)->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            $this->cachedEmailTable = '';
            return null;
        }

        foreach ($tables as $t) {
            if (strtolower((string)$t) === 'fetched_emails') {
                return $this->cachedEmailTable = (string)$t;
            }
        }
        foreach ($tables as $t) {
            $low = strtolower((string)$t);
            if ($low === 'emails' || $low === 'email') {
                return $this->cachedEmailTable = (string)$t;
            }
        }
        $this->cachedEmailTable = '';
        return null;
    }

    private function clampLimit(int $limit): int
    {
        if ($limit < 1) {
            return 1;
        }
        if ($limit > self::MAX_LIMIT) {
            return self::MAX_LIMIT;
        }
        return $limit;
    }

    /**
     * Run a SELECT and return rows, or [] when the underlying table is
     * missing. Other PDO errors are re-thrown — silent data loss is worse
     * than a 500 surface.
     *
     * @return array<int, array<string, mixed>>
     */
    private function selectOrEmpty(string $sql): array
    {
        try {
            $stmt = $this->pdo->query($sql);
        } catch (PDOException $e) {
            if ($this->isMissingTableError($e)) {
                return [];
            }
            throw $e;
        }
        return $stmt->fetchAll();
    }

    private function countOrZero(string $sql): int
    {
        try {
            return (int)$this->pdo->query($sql)->fetchColumn();
        } catch (PDOException $e) {
            if ($this->isMissingTableError($e)) {
                return 0;
            }
            throw $e;
        }
    }

    private function isMissingTableError(PDOException $e): bool
    {
        // MySQL error 1146 = "Table '…' doesn't exist".
        // Embedded in $e->errorInfo[1] for most drivers; match by code
        // *and* message for belt-and-braces compatibility.
        $code = $e->errorInfo[1] ?? null;
        if ((int)$code === 1146) {
            return true;
        }
        return stripos($e->getMessage(), "doesn't exist") !== false
            || stripos($e->getMessage(), 'Unknown table') !== false;
    }
}
