<?php
/**
 * View-level helper functions.
 *
 * These are **presentation helpers only** — things the sacred
 * dashboard_entry_loop.php partial calls directly. They live in the global
 * namespace because the partial is deliberately kept byte-for-byte identical
 * to its 0.4 shape, and the partial calls these as bare functions.
 *
 * Only presentation and string-shaping logic belongs here. Never put SQL,
 * database access, or side-effectful code in this file — those belong in
 * Repositories or Services.
 *
 * Loaded from DashboardController before rendering the view, using
 * `require_once` so repeated controller renders in the same request don't
 * redeclare functions.
 */

declare(strict_types=1);

if (!function_exists('seismo_magnitu_day_heading')) {
    /**
     * German day label for the dashboard date separators ("Heute", "Gestern").
     * Returns '' for non-positive timestamps.
     *
     * Local-time calendar comparison is deliberate — the user reads the
     * dashboard in Zurich, so "today" should mean "today in Zurich" even
     * though timestamps are stored UTC.
     */
    function seismo_magnitu_day_heading(int $unixTs): string
    {
        if ($unixTs <= 0) {
            return '';
        }
        // Compute "today" in PHP's current timezone. We enforce UTC in
        // bootstrap.php; views that want Zurich-local headings should adjust
        // here when we add a dedicated view-timezone constant (TODO, Slice 5).
        $todayStart    = strtotime('today');
        $itemDayStart  = strtotime(date('Y-m-d', $unixTs) . ' 00:00:00');
        $diffDays      = (int)(($todayStart - $itemDayStart) / 86400);

        if ($diffDays === 0) return 'Heute';
        if ($diffDays === 1) return 'Gestern';
        if ($diffDays === 2) return 'Vorgestern';
        if ($diffDays >= 3 && $diffDays <= 6) {
            return 'Heute -' . $diffDays;
        }
        return date('d.m.Y', $unixTs);
    }
}

if (!function_exists('seismo_feed_item_resolved_link')) {
    /**
     * Resolve a feed_items row to a usable article URL.
     *
     * Some feeds emit blank <link> elements and stash the URL in <guid>.
     * This helper hides that asymmetry from the view.
     */
    function seismo_feed_item_resolved_link(array $item): string
    {
        $link = trim((string)($item['link'] ?? ''));
        if ($link !== '') {
            return $link;
        }
        $guid = trim((string)($item['guid'] ?? ''));
        if ($guid !== '' && preg_match('#^https?://#i', $guid)) {
            return $guid;
        }
        return '';
    }
}

if (!function_exists('highlightSearchTerm')) {
    /**
     * Wrap matches of $searchQuery in a <mark> while escaping everything else.
     *
     * Slice 1 doesn't expose search UI, but the partial calls this function
     * in its "search active" branch. Ship a working implementation now so the
     * partial stays byte-identical once search returns in a later slice.
     */
    function highlightSearchTerm(?string $text, string $searchQuery): string
    {
        $text = (string)$text;
        if ($searchQuery === '' || $text === '') {
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $escapedText  = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escapedQuery = preg_quote($searchQuery, '/');
        $result       = preg_replace(
            '/' . $escapedQuery . '/iu',
            '<mark class="search-highlight">$0</mark>',
            $escapedText
        );
        return $result ?? $escapedText;
    }
}

if (!function_exists('getCalendarEventTypeLabel')) {
    /**
     * Friendly label for a Leg (calendar_events) event type. Input values
     * come straight from the Parlament.ch OData feed, which is inconsistent
     * about diacritics ("Geschäft" vs "Geschaeft") — both variants map here.
     */
    function getCalendarEventTypeLabel(?string $type): string
    {
        return match ($type) {
            'session'                                      => 'Session',
            'Motion'                                       => 'Motion',
            'Postulat'                                     => 'Postulat',
            'Interpellation',
            'Dringliche Interpellation'                    => 'Interpellation',
            'Einfache Anfrage',
            'Dringliche Einfache Anfrage'                  => 'Anfrage',
            'Parlamentarische Initiative'                  => 'Parl. Initiative',
            'Standesinitiative'                            => 'Standesinitiative',
            'Geschaeft des Bundesrates',
            'Geschäft des Bundesrates'                     => 'Bundesratsgeschäft',
            'Geschaeft des Parlaments',
            'Geschäft des Parlaments'                      => 'Parlamentsgeschäft',
            'Petition'                                     => 'Petition',
            'Empfehlung'                                   => 'Empfehlung',
            'Fragestunde. Frage'                           => 'Fragestunde',
            default                                        => $type !== null && $type !== '' ? $type : 'Event',
        };
    }
}

if (!function_exists('getCouncilLabel')) {
    /**
     * Expand a council code from Parlament.ch into a readable label.
     */
    function getCouncilLabel(?string $code): string
    {
        return match ($code) {
            'NR'    => 'Nationalrat',
            'SR'    => 'Ständerat',
            'BR'    => 'Bundesrat',
            default => (string)($code ?? ''),
        };
    }
}
