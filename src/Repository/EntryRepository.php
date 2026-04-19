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

    /**
     * Columns fetchEmails() will try to use for newest-first ordering, in
     * preference order. The subset that actually exists on the resolved email
     * table is detected at query time — see resolveEmailDateColumns().
     */
    private const EMAIL_DATE_COLUMNS = ['date_received', 'date_utc', 'created_at', 'date_sent'];

    private ?string $cachedEmailTable = null;

    /** @var array<int, string>|null Cached per-instance — see resolveEmailDateColumns(). */
    private ?array $cachedEmailDateColumns = null;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Merged newest-first timeline across every entry family.
     *
     * **Paging caveat.** Per-source fetches are capped at `$limit + $offset`
     * each, so `$offset` is *valid* (the merged slice is well-defined at the
     * head) but not *deep-page-safe* under heavy skew. If one family dumps
     * its whole per-source window into the most recent day while a quieter
     * family has rows further back, a caller asking for a deep offset will
     * be missing interleaved rows from the quieter family that exist but
     * weren't fetched.
     *
     * Slice 1 has no pagination UI, so `DashboardController` clamps
     * `MAX_OFFSET = 0` in practice. When paging UI returns we'll switch to
     * cursor-based paging (e.g. `?since_id=<id>` per family) rather than
     * offset, which side-steps the skew problem entirely.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLatestTimeline(int $limit, int $offset = 0): array
    {
        $limit  = $this->clampLimit($limit);
        $offset = max(0, $offset);

        // Per-source fetch size. Taking $limit + $offset from each source
        // guarantees we have enough rows to slice a valid offset/limit window
        // at the head. See the paging caveat above for why deep offsets are
        // not safe under heavy skew.
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
        // 0.4 shipped two email schemas (`emails` and `fetched_emails`) with
        // different date column sets. Build the ORDER BY from whichever of
        // EMAIL_DATE_COLUMNS the resolved table actually has, so an installer
        // running the older `emails` shape doesn't 500 on a missing
        // `date_received`. Slice 4's email unification retires this.
        $dateCols = $this->resolveEmailDateColumns($table);
        if ($dateCols === []) {
            // No recognised date column — fall back to id DESC. Ugly but
            // stable; the email unification migration resolves this properly.
            $orderBy = 'ORDER BY id DESC';
        } elseif (count($dateCols) === 1) {
            $orderBy = 'ORDER BY `' . $dateCols[0] . '` DESC';
        } else {
            $coalesce = implode(
                ', ',
                array_map(static fn (string $c) => '`' . $c . '`', $dateCols)
            );
            $orderBy = 'ORDER BY COALESCE(' . $coalesce . ') DESC';
        }
        $sql = 'SELECT * FROM ' . entryTable($table) . '
                ' . $orderBy . '
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
        $pairs = $this->collectEntryKeys($items);
        if ($pairs === []) {
            return;
        }
        // Row-value IN: pulls only the scores we actually need instead of
        // scanning entry_scores end-to-end. entry_scores is PK'd on
        // (entry_type, entry_id), so MariaDB uses the index directly.
        [$placeholders, $flat] = $this->rowValueInClause($pairs);
        $sql = 'SELECT entry_type, entry_id, relevance_score, predicted_label,
                       explanation, score_source
                FROM entry_scores
                WHERE (entry_type, entry_id) IN (' . $placeholders . ')';
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($flat);
            $rows = $stmt->fetchAll();
        } catch (PDOException $e) {
            return;
        }
        $map = [];
        foreach ($rows as $row) {
            $map[$row['entry_type'] . ':' . $row['entry_id']] = $row;
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
        $pairs = $this->collectEntryKeys($items);
        if ($pairs === []) {
            return;
        }
        [$placeholders, $flat] = $this->rowValueInClause($pairs);
        $sql = 'SELECT entry_type, entry_id
                FROM entry_favourites
                WHERE (entry_type, entry_id) IN (' . $placeholders . ')';
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($flat);
            $rows = $stmt->fetchAll();
        } catch (PDOException $e) {
            return;
        }
        $set = [];
        foreach ($rows as $row) {
            $set[$row['entry_type'] . ':' . $row['entry_id']] = true;
        }
        foreach ($items as &$item) {
            $key = $item['entry_type'] . ':' . $item['entry_id'];
            if (isset($set[$key])) {
                $item['is_favourite'] = true;
            }
        }
        unset($item);
    }

    /**
     * Pull (entry_type, entry_id) pairs out of the wrapped timeline, skipping
     * rows with missing/invalid keys. Deduped so repeats (unlikely, but
     * cheap to guard) don't bloat the IN clause.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array{0: string, 1: int}>
     */
    private function collectEntryKeys(array $items): array
    {
        $seen = [];
        $pairs = [];
        foreach ($items as $item) {
            $type = (string)($item['entry_type'] ?? '');
            $id   = (int)($item['entry_id'] ?? 0);
            if ($type === '' || $id <= 0) {
                continue;
            }
            $key = $type . ':' . $id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $pairs[] = [$type, $id];
        }
        return $pairs;
    }

    /**
     * Build a "(?, ?), (?, ?), ..." placeholder string and the flat parameter
     * array for a row-value IN clause.
     *
     * @param array<int, array{0: string, 1: int}> $pairs
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function rowValueInClause(array $pairs): array
    {
        $placeholders = implode(', ', array_fill(0, count($pairs), '(?, ?)'));
        $flat = [];
        foreach ($pairs as [$type, $id]) {
            $flat[] = $type;
            $flat[] = $id;
        }
        return [$placeholders, $flat];
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
     * TODO (deferred optimisation): the resolved name is stable across
     * deploys. Stash it in magnitu_config (key `resolved_email_table`) the
     * first time it's computed so subsequent requests skip the SHOW TABLES
     * round-trip. Tiny; not worth doing before Slice 4's email unification
     * removes the need entirely.
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

    /**
     * Subset of EMAIL_DATE_COLUMNS that physically exist on the resolved
     * email table, in declaration order. Queried once per request from
     * INFORMATION_SCHEMA so the `emails` vs `fetched_emails` column split
     * doesn't 500 the dashboard.
     *
     * In satellite mode we look up the mothership schema via
     * entryDbSchemaExpr() so we see the mothership's columns, not the
     * local (scoring-only) schema.
     *
     * @return array<int, string>
     */
    private function resolveEmailDateColumns(string $table): array
    {
        if ($this->cachedEmailDateColumns !== null) {
            return $this->cachedEmailDateColumns;
        }
        $placeholders = implode(', ', array_fill(0, count(self::EMAIL_DATE_COLUMNS), '?'));
        $sql = 'SELECT COLUMN_NAME
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = ' . entryDbSchemaExpr() . '
                  AND TABLE_NAME = ?
                  AND COLUMN_NAME IN (' . $placeholders . ')';
        $params = array_merge([$table], self::EMAIL_DATE_COLUMNS);
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $present = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            // INFORMATION_SCHEMA is always readable on MariaDB, so this
            // shouldn't fire. Fall back to the full candidate list — if a
            // column doesn't exist we'd previously 500; now we still might
            // if the fallback is wrong. Better than silently dropping all
            // email rows, and the whole path dies in Slice 4 anyway.
            return $this->cachedEmailDateColumns = self::EMAIL_DATE_COLUMNS;
        }
        $presentSet = array_flip(array_map('strval', $present));
        $ordered = [];
        foreach (self::EMAIL_DATE_COLUMNS as $col) {
            if (isset($presentSet[$col])) {
                $ordered[] = $col;
            }
        }
        return $this->cachedEmailDateColumns = $ordered;
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
