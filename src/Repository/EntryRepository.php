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
 *     Escaping is the view's job (e(), or seismo_highlight_search_term()).
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
     * Explicit feed_items column list for JOIN queries — never `fi.*` so joined
     * `feeds` columns can never collide with item fields in associative fetchers.
     */
    private const SQL_FEED_ITEMS_JOIN_SELECT = 'fi.id, fi.feed_id, fi.guid, fi.title, fi.link, fi.description, fi.content, fi.author,
            fi.published_date, fi.content_hash, fi.hidden, fi.cached_at';

    /**
     * Hard cap on the final timeline size.
     *
     * Per-source queries each take (limit + offset) rows so merge+sort produces
     * a stable window, which means worst-case memory is roughly
     *   5 families × MAX_LIMIT rows × ~10 KB/row ≈ 10 MB
     * — comfortably under the 128 MB shared-hosting default.
     */
    public const MAX_LIMIT = 200;

    /** Unified `emails` table (Slice 4 migration) — ordering preference. */
    private const EMAIL_DATE_COLUMNS = ['date_utc', 'date_received', 'created_at', 'date_sent'];

    /**
     * Per-table memo for {@see resolveEmailDateColumns()}.
     *
     * @var array<string, array<int, string>>
     */
    private array $cachedEmailDateColumns = [];

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
    public function getLatestTimeline(int $limit, int $offset = 0, ?TimelineFilter $filter = null, bool $sortByRelevance = false): array
    {
        $limit  = $this->clampLimit($limit);
        $offset = max(0, $offset);

        // Per-source fetch size. Taking $limit + $offset from each source
        // guarantees we have enough rows to slice a valid offset/limit window
        // at the head. See the paging caveat above for why deep offsets are
        // not safe under heavy skew.
        $perSource = $limit + $offset;
        $f        = $filter;

        $items = [];
        foreach ($this->fetchFeedItems($perSource, $f) as $row) {
            $items[] = $this->wrapFeedItem($row);
        }
        foreach ($this->fetchEmails($perSource, $f) as $row) {
            $items[] = $this->wrapEmail($row);
        }
        foreach ($this->fetchLexItems($perSource, $f) as $row) {
            $items[] = $this->wrapLexItem($row);
        }
        if ($f === null || $f->includeCalendar) {
            foreach ($this->fetchCalendarEvents($perSource) as $row) {
                $items[] = $this->wrapCalendarEvent($row);
            }
        }

        $this->attachScores($items);
        $this->sortMergedTimeline($items, $sortByRelevance);
        $items = array_slice($items, $offset, $limit);
        $this->attachFavourites($items);

        return $items;
    }

    /**
     * Full-text-ish search across all entry families (LIKE %term%).
     * Empty `$q` returns [] — callers should use getLatestTimeline instead.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchTimeline(string $q, int $limit, int $offset = 0, ?TimelineFilter $filter = null, bool $sortByRelevance = false): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $limit  = $this->clampLimit($limit);
        $offset = max(0, $offset);
        $perSource = $limit + $offset;
        // Escape LIKE wildcards in user input so "%" and "_" are literal (MariaDB default escape \).
        $term = '%' . $this->escapeLikePattern($q) . '%';
        $f    = $filter;

        $items = [];
        foreach ($this->fetchFeedItemsSearch($term, $perSource, $f) as $row) {
            $items[] = $this->wrapFeedItem($row);
        }
        foreach ($this->searchEmailRows($term, $perSource, $f) as $row) {
            $items[] = $this->wrapEmail($row);
        }
        foreach ($this->fetchLexItemsSearch($term, $perSource, $f) as $row) {
            $items[] = $this->wrapLexItem($row);
        }
        if ($f === null || $f->includeCalendar) {
            foreach ($this->fetchCalendarEventsSearch($term, $perSource) as $row) {
                $items[] = $this->wrapCalendarEvent($row);
            }
        }

        $this->attachScores($items);
        $this->sortMergedTimeline($items, $sortByRelevance);
        $items = array_slice($items, $offset, $limit);
        $this->attachFavourites($items);

        return $items;
    }

    /**
     * Dashboard "Magnitu highlights": rows scored by Magnitu at or above the
     * configured alert threshold. Hydrates feed / email / lex / Leg; sorts
     * newest-first by unified timeline `date`.
     *
     * Candidate rows are capped (recent `scored_at` first) so this stays
     * bounded on large `entry_scores` tables — a pragmatic trade vs scanning
     * every qualifying row on shared hosts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMagnituHighlightsTimeline(float $alertThreshold, int $limit, int $offset = 0): array
    {
        $limit  = $this->clampLimit($limit);
        $offset = max(0, $offset);
        $alertThreshold = max(0.0, min(1.0, $alertThreshold));
        $cap            = min(500, self::MAX_LIMIT * 3);
        try {
            $stmt = $this->pdo->prepare(
                'SELECT entry_type, entry_id, relevance_score, predicted_label, explanation, score_source
                 FROM entry_scores
                 WHERE score_source = \'magnitu\'
                   AND relevance_score >= ?
                   AND entry_type IN (\'feed_item\',\'email\',\'lex_item\',\'calendar_event\')
                 ORDER BY scored_at DESC
                 LIMIT ' . (int)$cap
            );
            $stmt->execute([$alertThreshold]);
            /** @var array<int, array<string, mixed>> $scoreRows */
            $scoreRows = $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            return [];
        }

        return $this->hydrateTimelineFromMagnituScoreRows($scoreRows, $limit, $offset);
    }

    /**
     * @param array<int, array<string, mixed>> $scoreRows
     * @return array<int, array<string, mixed>>
     */
    private function hydrateTimelineFromMagnituScoreRows(array $scoreRows, int $limit, int $offset): array
    {
        /** @var array<string, array<string, mixed>> $best */
        $best = [];
        foreach ($scoreRows as $row) {
            $t = (string)($row['entry_type'] ?? '');
            $id = (int)($row['entry_id'] ?? 0);
            if ($t === '' || $id <= 0) {
                continue;
            }
            $k = $t . ':' . $id;
            if (!isset($best[$k]) || (float)$row['relevance_score'] > (float)$best[$k]['relevance_score']) {
                $best[$k] = $row;
            }
        }
        if ($best === []) {
            return [];
        }

        $idsByType = [
            'feed_item'       => [],
            'email'           => [],
            'lex_item'        => [],
            'calendar_event'  => [],
        ];
        foreach ($best as $k => $_row) {
            $parts = explode(':', $k, 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$t, $idStr] = $parts;
            $id = (int)$idStr;
            if ($id <= 0 || !isset($idsByType[$t])) {
                continue;
            }
            $idsByType[$t][] = $id;
        }
        foreach ($idsByType as $t => $ids) {
            $idsByType[$t] = array_values(array_unique($ids));
        }

        $items = [];
        foreach ($this->fetchFeedRowsByIds($idsByType['feed_item']) as $row) {
            $w = $this->wrapFeedItem($row);
            $k = 'feed_item:' . $w['entry_id'];
            if (isset($best[$k])) {
                $w['score'] = $best[$k];
                $items[]  = $w;
            }
        }
        foreach ($this->fetchEmailRowsByIds($idsByType['email']) as $row) {
            $w = $this->wrapEmail($row);
            $k = 'email:' . $w['entry_id'];
            if (isset($best[$k])) {
                $w['score'] = $best[$k];
                $items[]  = $w;
            }
        }
        foreach ($this->fetchLexRowsByIds($idsByType['lex_item']) as $row) {
            $w = $this->wrapLexItem($row);
            $k = 'lex_item:' . $w['entry_id'];
            if (isset($best[$k])) {
                $w['score'] = $best[$k];
                $items[]  = $w;
            }
        }
        foreach ($this->fetchCalendarRowsByIds($idsByType['calendar_event']) as $row) {
            $w = $this->wrapCalendarEvent($row);
            $k = 'calendar_event:' . $w['entry_id'];
            if (isset($best[$k])) {
                $w['score'] = $best[$k];
                $items[]  = $w;
            }
        }

        $this->sortMergedTimeline($items, false);
        $items = array_slice($items, $offset, $limit);
        $this->attachFavourites($items);

        return $items;
    }

    /**
     * After {@see attachScores()}, order the merged multi-family window either
     * by entry date (default) or by relevance score then date (Magnitu setting).
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function sortMergedTimeline(array &$items, bool $byRelevance): void
    {
        if ($byRelevance) {
            usort(
                $items,
                static function (array $a, array $b): int {
                    $sa = isset($a['score']['relevance_score']) ? (float)$a['score']['relevance_score'] : -1.0;
                    $sb = isset($b['score']['relevance_score']) ? (float)$b['score']['relevance_score'] : -1.0;
                    if (($sb <=> $sa) !== 0) {
                        return $sb <=> $sa;
                    }

                    return ($b['date'] ?? 0) <=> ($a['date'] ?? 0);
                }
            );

            return;
        }

        usort(
            $items,
            static fn (array $a, array $b): int => ($b['date'] ?? 0) <=> ($a['date'] ?? 0)
        );
    }

    /**
     * All starred entries, merged and sorted by entry date (newest first).
     *
     * Loads up to {@see self::FAVOURITES_MAX_PAIRS} favourite rows from the
     * local `entry_favourites` table (most recently starred first). If a user
     * exceeds that cap, older stars are omitted until we add paging — a
     * deliberate shared-host guard.
     *
     * `$offset` is wired for API symmetry; {@see DashboardController} clamps
     * `MAX_OFFSET = 0` until cursor-based paging exists, so deep offsets do
     * not apply yet.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getFavouritesTimeline(int $limit, int $offset = 0, ?TimelineFilter $filter = null): array
    {
        $limit  = $this->clampLimit($limit);
        $offset = max(0, $offset);

        try {
            $stmt = $this->pdo->query(
                'SELECT entry_type, entry_id FROM entry_favourites
                 ORDER BY created_at DESC, id DESC
                 LIMIT ' . (int)self::FAVOURITES_MAX_PAIRS
            );
            $pairs = $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }

        $byType = [
            'feed_item'        => [],
            'email'            => [],
            'lex_item'         => [],
            'calendar_event'   => [],
        ];
        foreach ($pairs as $row) {
            $t = (string)($row['entry_type'] ?? '');
            $id = (int)($row['entry_id'] ?? 0);
            if (!isset($byType[$t]) || $id <= 0) {
                continue;
            }
            $byType[$t][] = $id;
        }
        foreach ($byType as $t => $ids) {
            $byType[$t] = array_values(array_unique($ids));
        }

        $items = [];
        foreach ($this->fetchFeedRowsByIds($byType['feed_item']) as $row) {
            $items[] = $this->wrapFeedItem($row);
        }
        foreach ($this->fetchEmailRowsByIds($byType['email']) as $row) {
            $items[] = $this->wrapEmail($row);
        }
        foreach ($this->fetchLexRowsByIds($byType['lex_item']) as $row) {
            $items[] = $this->wrapLexItem($row);
        }
        if ($filter === null || $filter->includeCalendar) {
            foreach ($this->fetchCalendarRowsByIds($byType['calendar_event']) as $row) {
                $items[] = $this->wrapCalendarEvent($row);
            }
        }

        foreach ($items as &$it) {
            $it['is_favourite'] = true;
        }
        unset($it);

        if ($filter !== null && $filter->isActive()) {
            $items = array_values(array_filter(
                $items,
                fn (array $it): bool => $this->itemMatchesTimelineFilter($it, $filter)
            ));
        }

        usort($items, static fn ($a, $b) => ($b['date'] ?? 0) <=> ($a['date'] ?? 0));
        $items = array_slice($items, $offset, $limit);

        $this->attachScores($items);

        return $items;
    }

    /**
     * Safety cap on how many (entry_type, entry_id) pairs we hydrate for the
     * favourites view. Unlikely to bite real users; keeps memory bounded.
     */
    private const FAVOURITES_MAX_PAIRS = 5000;

    /**
     * Total timeline size approximation (sum of per-family counts).
     * Bounded for the same reason the list is: counts are cheap but we
     * still don't want to scan unbounded partitions on each page load.
     */
    public function countLatestTimelineApprox(): int
    {
        $total = 0;
        $total += $this->countOrZero('SELECT COUNT(*) FROM ' . entryTable('feed_items'));
        $total += $this->countOrZero('SELECT COUNT(*) FROM ' . entryTable('emails'));
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
    private function fetchFeedItems(int $limit, ?TimelineFilter $filter = null): array
    {
        $extra = $this->feedSqlFilter($filter);
        $sql = '
            SELECT ' . self::SQL_FEED_ITEMS_JOIN_SELECT . ',
                   f.title       AS feed_title,
                   f.category    AS feed_category,
                   f.source_type AS feed_source_type,
                   f.url         AS feed_url,
                   f.title       AS feed_name
            FROM ' . entryTable('feed_items') . ' fi
            JOIN ' . entryTable('feeds') . ' f ON fi.feed_id = f.id
            WHERE f.disabled = 0
              AND fi.hidden = 0
              ' . $extra['sql'] . '
            ORDER BY fi.published_date DESC, fi.cached_at DESC
            LIMIT ' . (int)$limit;

        return $extra['params'] === []
            ? $this->selectOrEmpty($sql)
            : $this->selectPreparedOrEmpty($sql, $extra['params']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchEmails(int $limit, ?TimelineFilter $filter = null): array
    {
        $emailTag = $filter !== null && $filter->emailTag !== null && $filter->emailTag !== ''
            ? $filter->emailTag
            : null;

        $dateCols = $this->resolveEmailDateColumns('emails');
        if ($dateCols === []) {
            $orderBy = 'ORDER BY e.id DESC';
        } elseif (count($dateCols) === 1) {
            $orderBy = 'ORDER BY e.`' . $dateCols[0] . '` DESC';
        } else {
            $coalesce = implode(
                ', ',
                array_map(static fn (string $c) => '`e`.`' . $c . '`', $dateCols)
            );
            $orderBy = 'ORDER BY COALESCE(' . $coalesce . ') DESC';
        }

        if ($emailTag !== null) {
            $sql = 'SELECT e.*, stf.tag AS sender_tag
                    FROM ' . entryTable('emails') . ' e
                    INNER JOIN ' . entryTable('sender_tags') . ' stf
                      ON stf.from_email = e.from_email
                     AND stf.tag = ?
                     AND stf.removed_at IS NULL
                    ' . $orderBy . '
                    LIMIT ' . (int)$limit;

            return $this->selectPreparedOrEmpty($sql, [$emailTag]);
        }

        $sql = 'SELECT e.*, st.tag AS sender_tag
                FROM ' . entryTable('emails') . ' e
                LEFT JOIN ' . entryTable('sender_tags') . ' st
                  ON st.from_email = e.from_email AND st.removed_at IS NULL
                ' . $orderBy . '
                LIMIT ' . (int)$limit;

        return $this->selectOrEmpty($sql);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchLexItems(int $limit, ?TimelineFilter $filter = null): array
    {
        $where = '';
        $params = [];
        if ($filter !== null && $filter->lexSources !== []) {
            $ph = implode(',', array_fill(0, count($filter->lexSources), '?'));
            $where = ' WHERE source IN (' . $ph . ') ';
            $params = $filter->lexSources;
        }
        $sql = 'SELECT * FROM ' . entryTable('lex_items') . $where . '
                ORDER BY document_date DESC, created_at DESC
                LIMIT ' . (int)$limit;

        return $params === []
            ? $this->selectOrEmpty($sql)
            : $this->selectPreparedOrEmpty($sql, $params);
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
    // Search + favourites-by-id helpers (Slice 1.5)
    // ------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchFeedItemsSearch(string $term, int $limit, ?TimelineFilter $filter = null): array
    {
        $extra = $this->feedSqlFilter($filter);
        $sql = '
            SELECT ' . self::SQL_FEED_ITEMS_JOIN_SELECT . ',
                   f.title       AS feed_title,
                   f.category    AS feed_category,
                   f.source_type AS feed_source_type,
                   f.url         AS feed_url,
                   f.title       AS feed_name
            FROM ' . entryTable('feed_items') . ' fi
            JOIN ' . entryTable('feeds') . ' f ON fi.feed_id = f.id
            WHERE f.disabled = 0
              AND fi.hidden = 0
              ' . $extra['sql'] . '
              AND (
                    fi.title LIKE ?
                 OR fi.description LIKE ?
                 OR fi.content LIKE ?
              )
            ORDER BY fi.published_date DESC, fi.cached_at DESC
            LIMIT ' . (int)$limit;
        $p = array_merge($extra['params'], [$term, $term, $term]);

        return $this->selectPreparedOrEmpty($sql, $p);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchEmailRows(string $term, int $limit, ?TimelineFilter $filter = null): array
    {
        $cols = $this->resolveEmailSearchColumns('emails');
        if ($cols === []) {
            return [];
        }
        $parts = [];
        $params = [];
        foreach ($cols as $c) {
            $parts[] = '`e`.`' . str_replace('`', '``', $c) . '` LIKE ?';
            $params[] = $term;
        }
        $where = '(' . implode(' OR ', $parts) . ')';
        $orderBy = $this->buildEmailOrderByClause('emails');

        $emailTag = $filter !== null && $filter->emailTag !== null && $filter->emailTag !== ''
            ? $filter->emailTag
            : null;

        if ($emailTag !== null) {
            $sql = 'SELECT e.*, stf.tag AS sender_tag
                    FROM ' . entryTable('emails') . ' e
                    INNER JOIN ' . entryTable('sender_tags') . ' stf
                      ON stf.from_email = e.from_email
                     AND stf.tag = ?
                     AND stf.removed_at IS NULL
                    WHERE ' . $where . '
                    ' . $orderBy . '
                    LIMIT ' . (int)$limit;
            array_unshift($params, $emailTag);

            return $this->selectPreparedOrEmpty($sql, $params);
        }

        $sql = 'SELECT e.*, st.tag AS sender_tag
                FROM ' . entryTable('emails') . ' e
                LEFT JOIN ' . entryTable('sender_tags') . ' st
                  ON st.from_email = e.from_email AND st.removed_at IS NULL
                WHERE ' . $where . '
                ' . $orderBy . '
                LIMIT ' . (int)$limit;

        return $this->selectPreparedOrEmpty($sql, $params);
    }

    /**
     * Subset of columns we are willing to search on an email table.
     *
     * @var array<int, string>
     */
    private const EMAIL_SEARCH_COLUMNS = [
        'subject', 'text_body', 'html_body', 'from_email', 'from_name',
        'from_addr', 'body_text', 'body_html',
    ];

    /** @var array<string, array<int, string>> memo: table name -> columns */
    private array $emailSearchColumnsCache = [];

    /**
     * @return array<int, string>
     */
    private function resolveEmailSearchColumns(string $table): array
    {
        if (isset($this->emailSearchColumnsCache[$table])) {
            return $this->emailSearchColumnsCache[$table];
        }
        $placeholders = implode(', ', array_fill(0, count(self::EMAIL_SEARCH_COLUMNS), '?'));
        $sql = 'SELECT COLUMN_NAME
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = ' . entryDbSchemaExpr() . '
                  AND TABLE_NAME = ?
                  AND COLUMN_NAME IN (' . $placeholders . ')';
        $params = array_merge([$table], self::EMAIL_SEARCH_COLUMNS);
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $present = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return $this->emailSearchColumnsCache[$table] = [];
        }
        $presentSet = array_flip(array_map('strval', $present));
        $ordered = [];
        foreach (self::EMAIL_SEARCH_COLUMNS as $col) {
            if (isset($presentSet[$col])) {
                $ordered[] = $col;
            }
        }
        return $this->emailSearchColumnsCache[$table] = $ordered;
    }

    private function buildEmailOrderByClause(string $table): string
    {
        $dateCols = $this->resolveEmailDateColumns($table);
        if ($dateCols === []) {
            return 'ORDER BY e.id DESC';
        }
        if (count($dateCols) === 1) {
            return 'ORDER BY e.`' . $dateCols[0] . '` DESC';
        }
        $coalesce = implode(
            ', ',
            array_map(static fn (string $c) => '`e`.`' . $c . '`', $dateCols)
        );

        return 'ORDER BY COALESCE(' . $coalesce . ') DESC';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchLexItemsSearch(string $term, int $limit, ?TimelineFilter $filter = null): array
    {
        $lexWhere = '';
        $params = [$term, $term];
        $lexWhere = '';
        if ($filter !== null && $filter->lexSources !== []) {
            $ph = implode(',', array_fill(0, count($filter->lexSources), '?'));
            $lexWhere = ' AND source IN (' . $ph . ') ';
            $params = array_merge($params, $filter->lexSources);
        }
        $sql = 'SELECT * FROM ' . entryTable('lex_items') . '
                WHERE (title LIKE ? OR description LIKE ?)
                ' . $lexWhere . '
                ORDER BY document_date DESC, created_at DESC
                LIMIT ' . (int)$limit;

        return $this->selectPreparedOrEmpty($sql, $params);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchCalendarEventsSearch(string $term, int $limit): array
    {
        $sql = 'SELECT * FROM ' . entryTable('calendar_events') . '
                WHERE title LIKE ?
                   OR description LIKE ?
                   OR content LIKE ?
                ORDER BY event_date DESC
                LIMIT ' . (int)$limit;
        return $this->selectPreparedOrEmpty($sql, [$term, $term, $term]);
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function fetchFeedRowsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->chunkIds($ids, 400) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $sql = '
                SELECT ' . self::SQL_FEED_ITEMS_JOIN_SELECT . ',
                       f.title       AS feed_title,
                       f.category    AS feed_category,
                       f.source_type AS feed_source_type,
                       f.url         AS feed_url,
                       f.title       AS feed_name
                FROM ' . entryTable('feed_items') . ' fi
                JOIN ' . entryTable('feeds') . ' f ON fi.feed_id = f.id
                WHERE fi.id IN (' . $ph . ')
                  AND fi.hidden = 0';
            foreach ($this->selectPreparedOrEmpty($sql, array_map('intval', $chunk)) as $row) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function fetchEmailRowsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->chunkIds($ids, 400) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $sql = 'SELECT e.*, st.tag AS sender_tag
                    FROM ' . entryTable('emails') . ' e
                    LEFT JOIN ' . entryTable('sender_tags') . ' st
                      ON st.from_email = e.from_email AND st.removed_at IS NULL
                    WHERE e.id IN (' . $ph . ')';
            foreach ($this->selectPreparedOrEmpty($sql, array_map('intval', $chunk)) as $row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function fetchLexRowsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->chunkIds($ids, 400) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $sql = 'SELECT * FROM ' . entryTable('lex_items') . '
                    WHERE id IN (' . $ph . ')';
            foreach ($this->selectPreparedOrEmpty($sql, array_map('intval', $chunk)) as $row) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function fetchCalendarRowsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->chunkIds($ids, 400) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $sql = 'SELECT * FROM ' . entryTable('calendar_events') . '
                    WHERE id IN (' . $ph . ')';
            foreach ($this->selectPreparedOrEmpty($sql, array_map('intval', $chunk)) as $row) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, array<int, int>>
     */
    private function chunkIds(array $ids, int $chunk): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($n) => $n > 0)));
        if ($ids === []) {
            return [];
        }
        return array_chunk($ids, max(1, $chunk));
    }

    /**
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function selectPreparedOrEmpty(string $sql, array $params): array
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        } catch (PDOException $e) {
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return [];
            }
            throw $e;
        }
        return $stmt->fetchAll();
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

    // ------------------------------------------------------------------
    // Slice 8 — module pages (Feeds / Scraper / Mail) single-family timelines.
    // ------------------------------------------------------------------

    /**
     * RSS + Substack `feed_items` only (excludes scraper-linked feeds).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRssModuleTimeline(int $limit, int $offset): array
    {
        return $this->buildModuleFeedTimeline('rss_substack', $limit, $offset);
    }

    /**
     * Scraper-backed feed items (matches dashboard “Scraper” filter semantics).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getScraperModuleTimeline(int $limit, int $offset): array
    {
        return $this->buildModuleFeedTimeline('scraper', $limit, $offset);
    }

    /**
     * Newest emails only (same row shape as the merged dashboard).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getEmailModuleTimeline(int $limit, int $offset): array
    {
        $limit  = $this->clampLimit($limit);
        $offset = max(0, $offset);
        $rows   = $this->fetchEmailsPaged($limit, $offset);
        $items  = [];
        foreach ($rows as $row) {
            $items[] = $this->wrapEmail($row);
        }
        $this->attachScores($items);
        $this->attachFavourites($items);

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildModuleFeedTimeline(string $mode, int $limit, int $offset): array
    {
        $limit  = $this->clampLimit($limit);
        $offset = max(0, $offset);
        $rows   = $this->fetchFeedItemsForModule($mode, $limit, $offset);
        $items  = [];
        foreach ($rows as $row) {
            $items[] = $this->wrapFeedItem($row);
        }
        $this->attachScores($items);
        $this->attachFavourites($items);

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchFeedItemsForModule(string $mode, int $limit, int $offset): array
    {
        $fi = entryTable('feed_items');
        $f  = entryTable('feeds');
        $sc = entryTable('scraper_configs');
        if ($mode === 'rss_substack') {
            $extra = " AND (f.source_type IN ('rss', 'substack', 'parl_press'))
                AND (IFNULL(f.category, '') <> 'scraper')
                AND NOT EXISTS (SELECT 1 FROM {$sc} sc WHERE sc.url = f.url AND sc.disabled = 0)";
        } elseif ($mode === 'scraper') {
            $extra = " AND (
                f.source_type = 'scraper'
                OR IFNULL(f.category, '') = 'scraper'
                OR EXISTS (SELECT 1 FROM {$sc} sc2 WHERE sc2.url = f.url AND sc2.disabled = 0)
            )";
        } else {
            return [];
        }

        $sql = "
            SELECT " . self::SQL_FEED_ITEMS_JOIN_SELECT . ",
                   f.title       AS feed_title,
                   f.category    AS feed_category,
                   f.source_type AS feed_source_type,
                   f.url         AS feed_url,
                   f.title       AS feed_name
            FROM {$fi} fi
            JOIN {$f} f ON fi.feed_id = f.id
            WHERE f.disabled = 0
              AND fi.hidden = 0
              {$extra}
            ORDER BY fi.published_date DESC, fi.cached_at DESC
            LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset;

        return $this->selectOrEmpty($sql);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchEmailsPaged(int $limit, int $offset): array
    {
        $table = getEmailTableName();
        $emailT = entryTable($table);
        $dateCols = $this->resolveEmailDateColumns($table);
        if ($dateCols === []) {
            $orderBy = 'ORDER BY e.id DESC';
        } elseif (count($dateCols) === 1) {
            $orderBy = 'ORDER BY e.`' . $dateCols[0] . '` DESC';
        } else {
            $coalesce = implode(
                ', ',
                array_map(static fn (string $c) => '`e`.`' . $c . '`', $dateCols)
            );
            $orderBy = 'ORDER BY COALESCE(' . $coalesce . ') DESC';
        }
        $st = entryTable('sender_tags');
        $sql = 'SELECT e.*, st.tag AS sender_tag
                FROM ' . $emailT . ' e
                LEFT JOIN ' . $st . ' st
                  ON st.from_email = e.from_email AND st.removed_at IS NULL
                ' . $orderBy . '
                LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;

        return $this->selectOrEmpty($sql);
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

    /**
     * Extra WHERE clause fragments for `feeds` / `feed_items` when tag filters
     * are active (Slice 4).
     *
     * @return array{sql: string, params: list<mixed>}
     */
    private function feedSqlFilter(?TimelineFilter $filter): array
    {
        if ($filter === null) {
            return ['sql' => '', 'params' => []];
        }
        $sql    = [];
        $params = [];
        if ($filter->feedCategory !== null && $filter->feedCategory !== '') {
            $sql[]    = ' AND f.category = ?';
            $params[] = $filter->feedCategory;
        }
        if ($filter->feedSourceKind === 'substack') {
            $sql[] = " AND f.source_type = 'substack'";
        } elseif ($filter->feedSourceKind === 'scraper') {
            $sc = entryTable('scraper_configs');
            $sql[] = " AND (f.source_type = 'scraper' OR f.category = 'scraper'
                OR EXISTS (SELECT 1 FROM {$sc} sc WHERE sc.url = f.url AND sc.disabled = 0))";
        } elseif ($filter->feedSourceKind === 'rss') {
            $sc = entryTable('scraper_configs');
            $sql[] = " AND f.source_type NOT IN ('substack','scraper')
                AND (f.category IS NULL OR f.category != 'scraper')
                AND NOT EXISTS (SELECT 1 FROM {$sc} sc WHERE sc.url = f.url AND sc.disabled = 0)";
        }

        return ['sql' => implode('', $sql), 'params' => $params];
    }

    private function itemMatchesTimelineFilter(array $item, TimelineFilter $filter): bool
    {
        $et = (string)($item['entry_type'] ?? '');

        if (!$filter->includeCalendar && $et === 'calendar_event') {
            return false;
        }
        $data = $item['data'] ?? [];
        if (!is_array($data)) {
            $data = [];
        }

        if ($filter->feedCategory !== null && $filter->feedCategory !== '' && $et === 'feed_item') {
            if ((string)($data['feed_category'] ?? '') !== $filter->feedCategory) {
                return false;
            }
        }
        if ($filter->feedSourceKind !== null && $et === 'feed_item') {
            $st  = (string)($data['feed_source_type'] ?? '');
            $cat = (string)($data['feed_category'] ?? '');
            if ($filter->feedSourceKind === 'substack' && $st !== 'substack') {
                return false;
            }
            if ($filter->feedSourceKind === 'scraper') {
                $isScraper = $st === 'scraper' || $cat === 'scraper';
                if (!$isScraper) {
                    return false;
                }
            }
            if ($filter->feedSourceKind === 'rss') {
                if ($st === 'substack' || $st === 'scraper' || $cat === 'scraper') {
                    return false;
                }
            }
        }
        if ($filter->lexSources !== [] && $et === 'lex_item') {
            $src = (string)($data['source'] ?? '');
            if (!in_array($src, $filter->lexSources, true)) {
                return false;
            }
        }
        if ($filter->emailTag !== null && $filter->emailTag !== '' && $et === 'email') {
            if ((string)($data['sender_tag'] ?? '') !== $filter->emailTag) {
                return false;
            }
        }

        return true;
    }

    /**
     * Distinct values for dashboard tag pills (bounded).
     *
     * @return array{
     *   feed_categories: list<string>,
     *   lex_sources: list<string>,
     *   email_tags: list<string>,
     * }
     */
    public function getFilterPillOptions(): array
    {
        return [
            'feed_categories' => $this->selectDistinctFeedCategories(),
            'lex_sources'     => $this->selectDistinctLexSources(),
            'email_tags'      => $this->selectDistinctEmailTags(),
        ];
    }

    /**
     * @return list<string>
     */
    private function selectDistinctFeedCategories(): array
    {
        $sql = 'SELECT DISTINCT category FROM ' . entryTable('feeds') . '
            WHERE disabled = 0
              AND category IS NOT NULL
              AND category != \'\'
              AND category != \'unsortiert\'
            ORDER BY category ASC
            LIMIT 50';
        $rows = $this->selectOrEmpty($sql);
        $out  = [];
        foreach ($rows as $r) {
            $c = trim((string)($r['category'] ?? ''));
            if ($c !== '') {
                $out[] = $c;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function selectDistinctLexSources(): array
    {
        $sql = 'SELECT DISTINCT source FROM ' . entryTable('lex_items') . '
            ORDER BY source ASC
            LIMIT 50';
        $rows = $this->selectOrEmpty($sql);
        $out  = [];
        foreach ($rows as $r) {
            $s = trim((string)($r['source'] ?? ''));
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function selectDistinctEmailTags(): array
    {
        $sql = 'SELECT DISTINCT tag FROM ' . entryTable('sender_tags') . '
            WHERE tag IS NOT NULL
              AND tag != \'\'
              AND tag != \'unclassified\'
              AND (removed_at IS NULL)
            ORDER BY tag ASC
            LIMIT 50';

        $rows = $this->selectOrEmpty($sql);
        $out  = [];
        foreach ($rows as $r) {
            $t = trim((string)($r['tag'] ?? ''));
            if ($t !== '') {
                $out[] = $t;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Infrastructure.
    // ------------------------------------------------------------------

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
        if (isset($this->cachedEmailDateColumns[$table])) {
            return $this->cachedEmailDateColumns[$table];
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
            return $this->cachedEmailDateColumns[$table] = self::EMAIL_DATE_COLUMNS;
        }
        $presentSet = array_flip(array_map('strval', $present));
        $ordered = [];
        foreach (self::EMAIL_DATE_COLUMNS as $col) {
            if (isset($presentSet[$col])) {
                $ordered[] = $col;
            }
        }
        return $this->cachedEmailDateColumns[$table] = $ordered;
    }

    /**
     * Escape `\`, `%`, and `_` for use inside SQL LIKE patterns (MariaDB
     * default escape character is backslash).
     */
    private function escapeLikePattern(string $q): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
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
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
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
            if (PdoMysqlDiagnostics::isMissingTable($e)) {
                return 0;
            }
            throw $e;
        }
    }
}
