<?php
/**
 * Dashboard / timeline view.
 *
 * Slice 1.5: search (GET), newest/favourites toggle, star buttons, session flash.
 * Slice 4: tag-filter pills (0.4 parity).
 */

declare(strict_types=1);

/** @var array<int, array<string, mixed>> $allItems */
/** @var string $searchQuery */
/** @var bool $showDaySeparators */
/** @var bool $showFavourites */
/** @var string $returnQuery */
/** @var ?string $dashboardError */
/** @var string $currentView 'newest'|'favourites' */
/** @var string $emptyTimelineHint 'default'|'favourites'|'search'|'filters'|'selection_none' */
/** @var string $csrfField CSRF hidden input HTML from DashboardController::show() */
/** @var array{feed_categories: list<string>, lex_sources: list<string>, email_tags: list<string>} $filterPillOptions */
/** @var \Seismo\Repository\TimelineFilter $timelineFilter */
/** @var float $alertThreshold Magnitu alert threshold for “!” pill on cards (Slice 7a) */

$basePath = getBasePath();
$accent   = seismoBrandAccent();

$headerTitle    = seismoBrandTitle();
$headerSubtitle = !isSatellite() ? 'ein Prototyp von hektopascal.org | v' . SEISMO_VERSION : null;
$activeNav      = 'index';

/** @param array<string, scalar|null> $overrides */
$dashboardQs = static function (array $overrides) use ($searchQuery, $currentView): string {
    $p = $_GET;
    if (!is_array($p)) {
        $p = [];
    }
    $p['action'] = 'index';
    if ($searchQuery !== '') {
        $p['q'] = $searchQuery;
    }
    if ($currentView === 'favourites') {
        $p['view'] = 'favourites';
    } else {
        unset($p['view']);
    }
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($p[$k]);
        } else {
            $p[$k] = $v;
        }
    }

    return http_build_query($p);
};

$clearAllFiltersQs = $dashboardQs([
    'sel' => null, 'efc' => null, 'elx' => null, 'eet' => null, 'ecal' => null, 'ejus' => null,
    'fc' => null, 'fk' => null, 'lx' => null, 'etag' => null, 'leg' => null,
]);
$selNoneQs = $dashboardQs([
    'sel'   => 'none',
    'efc'   => null,
    'elx'   => null,
    'eet'   => null,
    'ecal'  => null,
    'ejus'  => null,
    'fc'    => null,
    'fk'    => null,
    'lx'    => null,
    'etag'  => null,
    'leg'   => null,
]);
$selAllQs = $clearAllFiltersQs;

$indexLinkParams = ['action' => 'index'];
if ($searchQuery !== '') {
    $indexLinkParams['q'] = $searchQuery;
}
$indexNewestQs = http_build_query($indexLinkParams);

$indexFavParams = ['action' => 'index', 'view' => 'favourites'];
if ($searchQuery !== '') {
    $indexFavParams['q'] = $searchQuery;
}
$indexFavouritesQs = http_build_query($indexFavParams);

$clearSearchParams = ['action' => 'index'];
if ($currentView === 'favourites') {
    $clearSearchParams['view'] = 'favourites';
}
$clearSearchQs = http_build_query($clearSearchParams);

$selectionNone = isset($_GET['sel']) && strtolower(trim((string)$_GET['sel'])) === 'none';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(seismoBrandTitle()) ?></title>
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css">
    <?php if ($accent): ?>
    <style>:root { --seismo-accent: <?= e($accent) ?>; }</style>
    <?php endif; ?>
</head>
<body>
    <div class="container">
        <?php require __DIR__ . '/partials/site_header.php'; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="message message-success"><?= e((string)$_SESSION['success']) ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="message message-error"><?= e((string)$_SESSION['error']) ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if ($dashboardError !== null): ?>
            <div class="message message-error"><?= e($dashboardError) ?></div>
        <?php endif; ?>

        <div class="search-section search-section-spaced">
            <form method="get" class="search-form">
                <input type="hidden" name="action" value="index">
                <?php if ($currentView === 'favourites'): ?>
                    <input type="hidden" name="view" value="favourites">
                <?php endif; ?>
                <input type="search" name="q" placeholder="Search entries…" class="search-input" value="<?= e($searchQuery) ?>" style="min-width: 12rem; flex: 1;">
                <button type="submit" class="btn btn-primary">Search</button>
                <?php if ($searchQuery !== ''): ?>
                    <a href="?<?= e($clearSearchQs) ?>" class="btn btn-secondary">Clear search</a>
                <?php endif; ?>
            </form>
            <div class="view-toggle view-toggle-bar view-toggle-below-search view-toggle-bar--split">
                <div class="view-toggle-group">
                    <span class="view-toggle-label">View:</span>
                    <a href="?<?= e($indexNewestQs) ?>" class="btn <?= $currentView === 'newest' ? 'btn-primary' : 'btn-secondary' ?>">Newest</a>
                    <a href="?<?= e($indexFavouritesQs) ?>" class="btn <?= $currentView === 'favourites' ? 'btn-primary' : 'btn-secondary' ?>">Favourites</a>
                </div>
                <div class="view-toggle-group">
                    <span class="view-toggle-label">Selection:</span>
                    <a href="?<?= e($selAllQs) ?>" class="btn <?= !$selectionNone ? 'btn-primary' : 'btn-secondary' ?>">All</a>
                    <a href="?<?= e($selNoneQs) ?>" class="btn <?= $selectionNone ? 'btn-primary' : 'btn-secondary' ?>">None</a>
                </div>
            </div>
            <?php
                $efcRaw = isset($_GET['efc']) && !is_array($_GET['efc']) ? trim((string)$_GET['efc']) : '';
                $elxRaw = isset($_GET['elx']) && !is_array($_GET['elx']) ? trim((string)$_GET['elx']) : '';
                $eetRaw = isset($_GET['eet']) && !is_array($_GET['eet']) ? trim((string)$_GET['eet']) : '';
                $fcRaw = isset($_GET['fc']) && !is_array($_GET['fc']) ? trim((string)$_GET['fc']) : '';
                $lxRaw = isset($_GET['lx']) && !is_array($_GET['lx']) ? trim((string)$_GET['lx']) : '';
                $etagRaw = isset($_GET['etag']) && !is_array($_GET['etag']) ? trim((string)$_GET['etag']) : '';
                $ecalRaw = isset($_GET['ecal']) && !is_array($_GET['ecal']) ? trim((string)$_GET['ecal']) : '';
                $ejusRaw = isset($_GET['ejus']) && !is_array($_GET['ejus']) ? trim((string)$_GET['ejus']) : '';

                $csvHas = static function (string $csv, string $token): bool {
                    foreach (explode(',', $csv) as $p) {
                        if (trim($p) === $token) {
                            return true;
                        }
                    }

                    return false;
                };
                /** Toggle token in a comma exclusion list (`efc`, `elx`, `eet`). */
                $toggleExclusionQs = static function (string $key, string $token) use ($dashboardQs): string {
                    $raw = isset($_GET[$key]) && !is_array($_GET[$key]) ? trim((string)$_GET[$key]) : '';
                    $parts = [];
                    foreach (explode(',', $raw) as $p) {
                        $p = trim($p);
                        if ($p !== '') {
                            $parts[] = $p;
                        }
                    }
                    $idx = array_search($token, $parts, true);
                    if ($idx !== false) {
                        unset($parts[$idx]);
                        $parts = array_values($parts);
                    } else {
                        $parts[] = $token;
                    }
                    $next = implode(',', $parts);

                    return $dashboardQs([
                        $key  => $next !== '' ? $next : null,
                        'sel' => null,
                    ]);
                };
                /** Inclusion list `fc`: pick only this category after Selection None, or toggle when `fc` already set. */
                $toggleFeedCategoryQs = static function (string $cat) use ($dashboardQs, $selectionNone, $fcRaw, $toggleExclusionQs): string {
                    if ($selectionNone) {
                        return $dashboardQs(['sel' => null, 'efc' => null, 'fc' => $cat]);
                    }
                    if ($fcRaw !== '') {
                        $parts = [];
                        foreach (explode(',', $fcRaw) as $p) {
                            $p = trim($p);
                            if ($p !== '') {
                                $parts[] = $p;
                            }
                        }
                        $idx = array_search($cat, $parts, true);
                        if ($idx !== false) {
                            unset($parts[$idx]);
                            $parts = array_values($parts);
                        } else {
                            $parts[] = $cat;
                        }
                        $next = implode(',', $parts);

                        return $dashboardQs(['fc' => $next !== '' ? $next : null, 'efc' => null, 'sel' => null]);
                    }

                    return $toggleExclusionQs('efc', $cat);
                };
                $toggleLexSourceQs = static function (string $src) use ($dashboardQs, $selectionNone, $lxRaw, $toggleExclusionQs): string {
                    if ($selectionNone) {
                        return $dashboardQs(['sel' => null, 'elx' => null, 'lx' => $src]);
                    }
                    if ($lxRaw !== '') {
                        $parts = [];
                        foreach (explode(',', $lxRaw) as $p) {
                            $p = trim($p);
                            if ($p !== '') {
                                $parts[] = $p;
                            }
                        }
                        $idx = array_search($src, $parts, true);
                        if ($idx !== false) {
                            unset($parts[$idx]);
                            $parts = array_values($parts);
                        } else {
                            $parts[] = $src;
                        }
                        $next = implode(',', $parts);

                        return $dashboardQs(['lx' => $next !== '' ? $next : null, 'elx' => null, 'sel' => null]);
                    }

                    return $toggleExclusionQs('elx', $src);
                };
                $toggleEmailTagQs = static function (string $tg) use ($dashboardQs, $selectionNone, $etagRaw, $toggleExclusionQs): string {
                    if ($selectionNone) {
                        return $dashboardQs(['sel' => null, 'eet' => null, 'etag' => $tg]);
                    }
                    if ($etagRaw !== '') {
                        $parts = [];
                        foreach (explode(',', $etagRaw) as $p) {
                            $p = trim($p);
                            if ($p !== '') {
                                $parts[] = $p;
                            }
                        }
                        $idx = array_search($tg, $parts, true);
                        if ($idx !== false) {
                            unset($parts[$idx]);
                            $parts = array_values($parts);
                        } else {
                            $parts[] = $tg;
                        }
                        $next = implode(',', $parts);

                        return $dashboardQs(['etag' => $next !== '' ? $next : null, 'eet' => null, 'sel' => null]);
                    }

                    return $toggleExclusionQs('eet', $tg);
                };
                $excludeCal = ($ecalRaw === '1');
                $excludeJus = ($ejusRaw === '1');
                if ($selectionNone) {
                    $legToggleQs = $dashboardQs(['sel' => null, 'ecal' => null, 'ejus' => '1']);
                    $jusToggleQs = $dashboardQs(['sel' => null, 'ecal' => '1', 'ejus' => null]);
                } else {
                    $legToggleQs = $dashboardQs(['ecal' => $excludeCal ? null : '1', 'sel' => null]);
                    $jusToggleQs = $dashboardQs(['ejus' => $excludeJus ? null : '1', 'sel' => null]);
                }
            ?>
            <div class="tag-pills-section filter-toolbar">
                <div class="filter-toolbar__head">
                    <span class="filter-toolbar__label">Filters</span>
                    <a href="?<?= e($clearAllFiltersQs) ?>" class="filter-toolbar__clear-all">Reset all</a>
                </div>
                <?php if ($filterPillOptions['feed_categories'] !== []): ?>
                <div class="filter-toolbar__row">
                    <span class="filter-toolbar__hint">Feed</span>
                    <?php foreach ($filterPillOptions['feed_categories'] as $cat): ?>
                        <?php
                            $fcClass = ($cat === 'scraper') ? 'filter-pill--scraper' : 'filter-pill--feed';
                            $fcOn    = $fcRaw !== '' ? $csvHas($fcRaw, $cat) : !$csvHas($efcRaw, $cat);
                        ?>
                        <a href="?<?= e($toggleFeedCategoryQs($cat)) ?>"
                           class="filter-pill <?= e($fcClass) ?><?= $fcOn ? ' filter-pill--active' : '' ?>"><?= e($cat) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if ($filterPillOptions['lex_sources'] !== []): ?>
                <div class="filter-toolbar__row">
                    <span class="filter-toolbar__hint">Lex</span>
                    <?php foreach ($filterPillOptions['lex_sources'] as $src): ?>
                        <?php $lxOn = $lxRaw !== '' ? $csvHas($lxRaw, $src) : !$csvHas($elxRaw, $src); ?>
                        <a href="?<?= e($toggleLexSourceQs($src)) ?>"
                           class="filter-pill filter-pill--lex<?= $lxOn ? ' filter-pill--active' : '' ?>"><?= e($src) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if ($filterPillOptions['email_tags'] !== []): ?>
                <div class="filter-toolbar__row">
                    <span class="filter-toolbar__hint">Email tag</span>
                    <?php foreach ($filterPillOptions['email_tags'] as $tg): ?>
                        <?php $etOn = $etagRaw !== '' ? $csvHas($etagRaw, $tg) : !$csvHas($eetRaw, $tg); ?>
                        <a href="?<?= e($toggleEmailTagQs($tg)) ?>"
                           class="filter-pill filter-pill--mail<?= $etOn ? ' filter-pill--active' : '' ?>"><?= e($tg) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="filter-toolbar__row">
                    <span class="filter-toolbar__hint">Leg / Jus</span>
                    <a href="?<?= e($legToggleQs) ?>"
                       class="filter-pill filter-pill--leg<?= !$excludeCal ? ' filter-pill--active' : '' ?>"
                       title="Parliamentary calendar (Leg)">Leg</a>
                    <a href="?<?= e($jusToggleQs) ?>"
                       class="filter-pill filter-pill--lex<?= !$excludeJus ? ' filter-pill--active' : '' ?>"
                       title="Swiss case law (BGer / BGE / BVGE)">Jus</a>
                </div>
            </div>
        </div>

        <div class="latest-entries-section">
            <div class="section-title-row">
                <h2 class="section-title">
                    <?= count($allItems) ?> <?= count($allItems) === 1 ? 'entry' : 'entries' ?>
                </h2>
                <button class="btn btn-secondary entry-expand-all-btn">expand all &#9660;</button>
            </div>

            <?php if ($dashboardError !== null): ?>
                <?php // Error banner above — no empty-state. ?>
            <?php elseif ($allItems !== []): ?>
                <?php include __DIR__ . '/partials/dashboard_entry_loop.php'; ?>
            <?php else: ?>
                <div class="empty-state">
                    <?php if ($emptyTimelineHint === 'favourites'): ?>
                        <p>No favourites yet. Star entries with the ☆ button on each card, or switch back to <a href="?<?= e($indexNewestQs) ?>">Newest</a>.</p>
                    <?php elseif ($emptyTimelineHint === 'search'): ?>
                        <p>No entries match your search. Try different words or <a href="?action=index">clear the query</a>.</p>
                    <?php elseif ($emptyTimelineHint === 'selection_none'): ?>
                        <p>Selection is <strong>None</strong> — the timeline is intentionally empty. Switch to <a href="?<?= e($selAllQs) ?>">All</a> to show entries again.</p>
                    <?php elseif ($emptyTimelineHint === 'filters'): ?>
                        <p>No entries match the current filters. <a href="?<?= e($clearAllFiltersQs) ?>">Reset all filters</a> or widen the selection.</p>
                    <?php else: ?>
                        <p>No entries yet. Run <code>?action=migrate</code> if this is a fresh install, then come back once a fetcher has populated the database.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    (function() {
        function collapse(card, btn) {
            var preview = card.querySelector('.entry-preview');
            var full    = card.querySelector('.entry-full-content');
            if (!preview || !full) return;
            full.style.display = 'none';
            preview.style.display = '';
            if (btn) btn.textContent = 'expand \u25BC';
        }
        function expand(card, btn) {
            var preview = card.querySelector('.entry-preview');
            var full    = card.querySelector('.entry-full-content');
            if (!preview || !full) return;
            preview.style.display = 'none';
            full.style.display    = 'block';
            if (btn) btn.textContent = 'collapse \u25B2';
        }
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.entry-expand-btn');
            if (!btn) return;
            var card = btn.closest('.entry-card');
            var full = card.querySelector('.entry-full-content');
            if (!full) return;
            full.style.display === 'block' ? collapse(card, btn) : expand(card, btn);
        });
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.entry-expand-all-btn');
            if (!btn) return;
            var isExpanded = btn.dataset.expanded === 'true';
            document.querySelectorAll('.entry-card').forEach(function(card) {
                var cardBtn = card.querySelector('.entry-expand-btn');
                isExpanded ? collapse(card, cardBtn) : expand(card, cardBtn);
            });
            btn.dataset.expanded = !isExpanded;
            btn.textContent = !isExpanded ? 'collapse all \u25B2' : 'expand all \u25BC';
        });
    })();
    </script>
</body>
</html>
