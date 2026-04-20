<?php
/**
 * Dashboard / timeline controller.
 *
 * Slice 1.5 adds read-only search (`?q=`), favourites view (`?view=favourites`),
 * per-card star buttons, and delegates the POST toggle to FavouriteController.
 */

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Http\CsrfToken;
use Seismo\Repository\EntryRepository;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Repository\TimelineFilter;

final class DashboardController
{
    /** Fallback when `ui:dashboard_limit` is not set in system_config. */
    public const DEFAULT_LIMIT_FALLBACK = 30;

    /**
     * Deep-paging guard — see EntryRepository::getLatestTimeline().
     */
    private const MAX_OFFSET = 0;

    /**
     * Session cache for the three `SELECT DISTINCT` queries that feed the
     * filter pills on the dashboard. Per `consolidation-plan.md` the option
     * list changes at most once per refresh cycle, so a one-minute cache is
     * ample and removes three queries from the hot path.
     *
     * Kept in the controller (not the repository) so {@see EntryRepository}
     * stays SQL-only; the cache degrades gracefully when the session isn't
     * active (e.g. CLI or an error in early session bootstrap).
     */
    private const FILTER_PILL_CACHE_KEY = '_seismo_filter_pill_opts';
    private const FILTER_PILL_CACHE_AT  = '_seismo_filter_pill_at';
    private const FILTER_PILL_CACHE_TTL = 60;

    public function show(): void
    {
        $csrfField = CsrfToken::field();

        $limit  = $this->clampLimit($_GET['limit'] ?? null);
        $offset = $this->clampOffset($_GET['offset'] ?? null);

        $searchQuery = trim((string)($_GET['q'] ?? ''));
        $currentView = (isset($_GET['view']) && (string)$_GET['view'] === 'favourites')
            ? 'favourites'
            : 'newest';

        $dashboardError = null;
        $allItems        = [];
        $timelineFilter  = TimelineFilter::fromQueryArray($_GET);
        $filterPillOptions = ['feed_categories' => [], 'lex_sources' => [], 'email_tags' => []];
        $alertThreshold    = $this->resolveAlertThreshold();
        $sortByRelevance   = $currentView !== 'favourites' && $this->resolveSortByRelevance();

        try {
            $pdo  = getDbConnection();
            $repo = new EntryRepository($pdo);
            $filterPillOptions = $this->getFilterPillOptionsCached($repo);
            if ($currentView === 'favourites') {
                $allItems = $repo->getFavouritesTimeline($limit, $offset, $timelineFilter);
            } elseif ($searchQuery !== '') {
                $allItems = $repo->searchTimeline($searchQuery, $limit, $offset, $timelineFilter, $sortByRelevance);
            } else {
                $allItems = $repo->getLatestTimeline($limit, $offset, $timelineFilter, $sortByRelevance);
            }
        } catch (\Throwable $e) {
            error_log('Seismo dashboard: ' . $e->getMessage());
            $dashboardError = 'Database error. Check error_log for details.';
        }

        require_once SEISMO_ROOT . '/views/helpers.php';

        $showDaySeparators   = true;
        $showFavourites      = true;
        $showTimelineRefresh = true;
        $returnQuery         = $this->buildReturnQuery();

        $emptyTimelineHint = 'default';
        if ($dashboardError === null) {
            if ($currentView === 'favourites') {
                $emptyTimelineHint = 'favourites';
            } elseif ($searchQuery !== '') {
                $emptyTimelineHint = 'search';
            } elseif ($timelineFilter->isActive()) {
                $emptyTimelineHint = 'filters';
            }
        }

        require SEISMO_ROOT . '/views/index.php';
    }

    /**
     * Preserve dashboard GET state for favourite form round-trips (no leading "?").
     */
    private function buildReturnQuery(): string
    {
        $p = $_GET;
        if (!is_array($p)) {
            $p = [];
        }
        $p['action'] = 'index';
        return http_build_query($p);
    }

    private function clampLimit(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return $this->resolveDefaultLimitFromConfig();
        }
        $n = (int)$raw;
        if ($n <= 0) {
            return $this->resolveDefaultLimitFromConfig();
        }
        if ($n > EntryRepository::MAX_LIMIT) {
            return EntryRepository::MAX_LIMIT;
        }

        return $n;
    }

    /**
     * Magnitu "alert" badge threshold (0.0–1.0). Stored in `system_config`;
     * defaults to 0.75 when unset (matches Magnitu settings form default).
     */
    private function resolveAlertThreshold(): float
    {
        try {
            $config = new SystemConfigRepository(getDbConnection());
            $raw    = $config->get('alert_threshold');
            if ($raw !== null && $raw !== '' && is_numeric($raw)) {
                return max(0.0, min(1.0, (float)$raw));
            }
        } catch (\Throwable $e) {
            // fall through
        }

        return 0.75;
    }

    /**
     * When true, merged timeline sorts by relevance_score then entry date
     * (newest + search views only — not favourites).
     */
    private function resolveSortByRelevance(): bool
    {
        try {
            $config = new SystemConfigRepository(getDbConnection());

            return ((string)$config->get('sort_by_relevance')) === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function resolveDefaultLimitFromConfig(): int
    {
        try {
            $config = new SystemConfigRepository(getDbConnection());
            $raw    = $config->get(SettingsController::KEY_DASHBOARD_LIMIT);
            if ($raw !== null && $raw !== '' && ctype_digit($raw)) {
                return max(1, min(EntryRepository::MAX_LIMIT, (int)$raw));
            }
        } catch (\Throwable $e) {
            // Fresh install / transient DB — fall back.
        }

        return self::DEFAULT_LIMIT_FALLBACK;
    }

    /**
     * One-minute memo of `$repo->getFilterPillOptions()` in `$_SESSION`.
     * Falls through to the raw repo call when the session isn't writable.
     *
     * @return array{feed_categories: list<string>, lex_sources: list<string>, email_tags: list<string>}
     */
    private function getFilterPillOptionsCached(EntryRepository $repo): array
    {
        if (session_status() === PHP_SESSION_ACTIVE
            && isset($_SESSION[self::FILTER_PILL_CACHE_KEY], $_SESSION[self::FILTER_PILL_CACHE_AT])
            && (time() - (int)$_SESSION[self::FILTER_PILL_CACHE_AT]) < self::FILTER_PILL_CACHE_TTL
        ) {
            /** @var array{feed_categories: list<string>, lex_sources: list<string>, email_tags: list<string>} $cached */
            $cached = $_SESSION[self::FILTER_PILL_CACHE_KEY];

            return $cached;
        }

        $options = $repo->getFilterPillOptions();

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::FILTER_PILL_CACHE_KEY] = $options;
            $_SESSION[self::FILTER_PILL_CACHE_AT]  = time();
        }

        return $options;
    }

    private function clampOffset(mixed $raw): int
    {
        $n = (int)$raw;
        if ($n <= 0) {
            return 0;
        }
        return $n > self::MAX_OFFSET ? self::MAX_OFFSET : $n;
    }
}
