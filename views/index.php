<?php
/**
 * Dashboard / timeline view.
 *
 * Slice 1.5: search (GET), newest/favourites toggle, star buttons, session flash.
 * Slice 4 (legacy): tag-filter pills on index — superseded by ?action=filter.
 */

declare(strict_types=1);

/** @var array<int, array<string, mixed>> $allItems */
/** @var string $searchQuery */
/** @var bool $showDaySeparators */
/** @var bool $showFavourites */
/** @var string $returnQuery */
/** @var ?string $dashboardError */
/** @var string $currentView 'newest'|'favourites' */
/** @var string $emptyTimelineHint 'default'|'favourites'|'search'|'filters' */
/** @var string $csrfField CSRF hidden input HTML from DashboardController::show() */
/** @var \Seismo\Repository\TimelineFilter $timelineFilter */
/** @var float $alertThreshold Magnitu alert threshold for “!” pill on cards (Slice 7a) */

$basePath = getBasePath();
$accent   = seismoBrandAccent();

$headerTitle    = seismoBrandTitle();
$headerSubtitle = !isSatellite() ? 'ein Prototyp von hektopascal.org | v' . SEISMO_VERSION : null;
$activeNav      = 'index';

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

$filterPageParams = ['action' => 'filter'];
foreach (['q', 'view', 'limit', 'offset', 'none', 'filter_form', 'filters'] as $k) {
    if (!isset($_GET[$k])) {
        continue;
    }
    $v = $_GET[$k];
    if (is_array($v)) {
        $filterPageParams[$k] = $v;
    } elseif (is_scalar($v)) {
        $filterPageParams[$k] = $v;
    }
}
$filterPageQs = http_build_query($filterPageParams);
$filterNavQs  = $filterPageQs;

$clearTimelineFiltersParams = ['action' => 'index'];
if ($searchQuery !== '') {
    $clearTimelineFiltersParams['q'] = $searchQuery;
}
if ($currentView === 'favourites') {
    $clearTimelineFiltersParams['view'] = 'favourites';
}
$clearTimelineFiltersQs = http_build_query($clearTimelineFiltersParams);
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
                <?php
                $ff = $_GET['filter_form'] ?? null;
                if (is_scalar($ff) && trim((string)$ff) !== '') {
                    echo '<input type="hidden" name="filter_form" value="' . e((string)$ff) . '">';
                }
                $noneP = $_GET['none'] ?? null;
                if (is_scalar($noneP) && trim((string)$noneP) !== '') {
                    echo '<input type="hidden" name="none" value="' . e((string)$noneP) . '">';
                }
                if (isset($_GET['filters']) && is_array($_GET['filters'])) {
                    foreach ($_GET['filters'] as $fk => $fv) {
                        if (!is_string($fk) || !preg_match('/^[a-z]+$/', $fk)) {
                            continue;
                        }
                        if (is_array($fv)) {
                            foreach ($fv as $item) {
                                if (!is_scalar($item)) {
                                    continue;
                                }
                                $s = trim((string)$item);
                                if ($s === '') {
                                    continue;
                                }
                                echo '<input type="hidden" name="filters[' . e($fk) . '][]" value="' . e($s) . '">';
                            }
                        } elseif (is_scalar($fv)) {
                            echo '<input type="hidden" name="filters[' . e($fk) . ']" value="' . e(trim((string)$fv)) . '">';
                        }
                    }
                }
                ?>
                <input type="search" name="q" placeholder="Search entries…" class="search-input" value="<?= e($searchQuery) ?>" style="min-width: 12rem; flex: 1;">
                <button type="submit" class="btn btn-primary">Search</button>
                <?php if ($searchQuery !== ''): ?>
                    <a href="?<?= e($clearSearchQs) ?>" class="btn btn-secondary">Clear search</a>
                <?php endif; ?>
            </form>
            <div class="view-toggle view-toggle-bar view-toggle-below-search">
                <div class="view-toggle-group">
                    <span class="view-toggle-label">View:</span>
                    <a href="?<?= e($indexNewestQs) ?>" class="btn <?= $currentView === 'newest' ? 'btn-primary' : 'btn-secondary' ?>">Newest</a>
                    <a href="?<?= e($indexFavouritesQs) ?>" class="btn <?= $currentView === 'favourites' ? 'btn-primary' : 'btn-secondary' ?>">Favourites</a>
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
                    <?php elseif ($emptyTimelineHint === 'filters'): ?>
                        <p>No entries match the current filters. <a href="?<?= e($clearTimelineFiltersQs) ?>">Show everything on the timeline</a> or <a href="?<?= e($filterPageQs) ?>">adjust filters</a>.</p>
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
