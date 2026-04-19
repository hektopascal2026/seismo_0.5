<?php
/**
 * Dashboard / timeline controller.
 *
 * Slice 1 scope: a read-only, merged newest-first timeline. No tag filters,
 * no search, no favourites view, no refresh button — those return as their
 * own slices land so the diff stays reviewable.
 *
 * All SQL is delegated to EntryRepository. This class is a thin orchestrator:
 * pull the bounded query parameters, call the repo, hand the result to the
 * view, and let the sacred dashboard_entry_loop.php partial render cards.
 */

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Repository\EntryRepository;

final class DashboardController
{
    /** Default timeline size on first paint. Kept conservative for shared hosts. */
    private const DEFAULT_LIMIT = 30;

    public function show(): void
    {
        $limit  = $this->clampLimit($_GET['limit']  ?? null);
        $offset = $this->clampOffset($_GET['offset'] ?? null);

        try {
            $pdo  = getDbConnection();
            $repo = new EntryRepository($pdo);
            $allItems = $repo->getLatestTimeline($limit, $offset);
        } catch (\Throwable $e) {
            // Surface the failure on-screen rather than a blank 500 page —
            // the dashboard is where most 0.5 smoke tests will land.
            error_log('Seismo dashboard: ' . $e->getMessage());
            $allItems = [];
            $dashboardError = 'Database error. Check error_log for details.';
        }

        // View-level helpers (seismo_magnitu_day_heading etc.). Loaded here
        // so controllers that need them don't also need to know which view
        // partial they end up rendering.
        require_once SEISMO_ROOT . '/views/helpers.php';

        // Variables consumed by views/index.php and the partial. Kept minimal
        // for Slice 1 — the partial tolerates missing optional fields via
        // isset()/empty() checks.
        $searchQuery       = '';
        $showDaySeparators = true;
        $showFavourites    = false; // favourites POST route ports in a later slice
        $returnQuery       = 'action=index';
        $dashboardError    = $dashboardError ?? null;

        require SEISMO_ROOT . '/views/index.php';
    }

    private function clampLimit(mixed $raw): int
    {
        $n = (int)$raw;
        if ($n <= 0) {
            return self::DEFAULT_LIMIT;
        }
        if ($n > EntryRepository::MAX_LIMIT) {
            return EntryRepository::MAX_LIMIT;
        }
        return $n;
    }

    private function clampOffset(mixed $raw): int
    {
        $n = (int)$raw;
        return $n < 0 ? 0 : $n;
    }
}
