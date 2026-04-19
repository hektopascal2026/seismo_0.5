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

final class DashboardController
{
    /** Default timeline size on first paint. Kept conservative for shared hosts. */
    private const DEFAULT_LIMIT = 30;

    /**
     * Deep-paging guard — see EntryRepository::getLatestTimeline().
     */
    private const MAX_OFFSET = 0;

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

        try {
            $pdo  = getDbConnection();
            $repo = new EntryRepository($pdo);
            if ($currentView === 'favourites') {
                $allItems = $repo->getFavouritesTimeline($limit, $offset);
            } elseif ($searchQuery !== '') {
                $allItems = $repo->searchTimeline($searchQuery, $limit, $offset);
            } else {
                $allItems = $repo->getLatestTimeline($limit, $offset);
            }
        } catch (\Throwable $e) {
            error_log('Seismo dashboard: ' . $e->getMessage());
            $dashboardError = 'Database error. Check error_log for details.';
        }

        require_once SEISMO_ROOT . '/views/helpers.php';

        $showDaySeparators = true;
        $showFavourites    = true;
        $returnQuery       = $this->buildReturnQuery();

        $emptyTimelineHint = 'default';
        if ($dashboardError === null) {
            if ($currentView === 'favourites') {
                $emptyTimelineHint = 'favourites';
            } elseif ($searchQuery !== '') {
                $emptyTimelineHint = 'search';
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
        if ($n <= 0) {
            return 0;
        }
        return $n > self::MAX_OFFSET ? self::MAX_OFFSET : $n;
    }
}
