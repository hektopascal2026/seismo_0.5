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
/** @var string $emptyTimelineHint 'default'|'favourites'|'search'|'filters' */
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
            <div class="view-toggle view-toggle-bar view-toggle-below-search">
                <span class="view-toggle-label">View:</span>
                <a href="?<?= e($indexNewestQs) ?>" class="btn <?= $currentView === 'newest' ? 'btn-primary' : 'btn-secondary' ?>">Newest</a>
                <a href="?<?= e($indexFavouritesQs) ?>" class="btn <?= $currentView === 'favourites' ? 'btn-primary' : 'btn-secondary' ?>">Favourites</a>
            </div>
            <?php
                $fkRaw = isset($_GET['fk']) && !is_array($_GET['fk']) ? trim((string)$_GET['fk']) : '';
                $fcRaw = isset($_GET['fc']) && !is_array($_GET['fc']) ? trim((string)$_GET['fc']) : '';
                $lxRaw = isset($_GET['lx']) && !is_array($_GET['lx']) ? trim((string)$_GET['lx']) : '';
                $etagRaw = isset($_GET['etag']) && !is_array($_GET['etag']) ? trim((string)$_GET['etag']) : '';
                $legRaw = isset($_GET['leg']) && !is_array($_GET['leg']) ? trim((string)$_GET['leg']) : '';

                $csvHas = static function (string $csv, string $token): bool {
                    foreach (explode(',', $csv) as $p) {
                        if (trim($p) === $token) {
                            return true;
                        }
                    }

                    return false;
                };
                $toggleCsvQs = static function (string $key, string $token) use ($dashboardQs): string {
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

                    return $dashboardQs([$key => $next !== '' ? $next : null]);
                };
                $clearAllFiltersQs = $dashboardQs(['fc' => null, 'fk' => null, 'lx' => null, 'etag' => null, 'leg' => null]);
                $legActive = ($legRaw === '1');
                $legToggleQs = $dashboardQs(['leg' => $legActive ? null : '1']);
            ?>
            <div class="tag-pills-section filter-toolbar">
                <div class="filter-toolbar__head">
                    <span class="filter-toolbar__label">Filters</span>
                    <a href="?<?= e($clearAllFiltersQs) ?>" class="filter-toolbar__clear-all">Reset all</a>
                </div>
                <div class="filter-toolbar__row">
                    <span class="filter-toolbar__hint">Feed type</span>
                    <a href="?<?= e($dashboardQs(['fk' => null])) ?>"
                       class="filter-pill filter-pill--none<?= $fkRaw === '' ? ' filter-pill--active' : '' ?>">None</a>
                    <a href="?<?= e($toggleCsvQs('fk', 'rss')) ?>"
                       class="filter-pill filter-pill--feed<?= $csvHas($fkRaw, 'rss') ? ' filter-pill--active' : '' ?>">RSS</a>
                    <a href="?<?= e($toggleCsvQs('fk', 'substack')) ?>"
                       class="filter-pill filter-pill--feed<?= $csvHas($fkRaw, 'substack') ? ' filter-pill--active' : '' ?>">Substack</a>
                    <a href="?<?= e($toggleCsvQs('fk', 'scraper')) ?>"
                       class="filter-pill filter-pill--scraper<?= $csvHas($fkRaw, 'scraper') ? ' filter-pill--active' : '' ?>">Scraper</a>
                </div>
                <?php if ($filterPillOptions['feed_categories'] !== []): ?>
                <div class="filter-toolbar__row">
                    <span class="filter-toolbar__hint">Feed category</span>
                    <a href="?<?= e($dashboardQs(['fc' => null])) ?>"
                       class="filter-pill filter-pill--none<?= $fcRaw === '' ? ' filter-pill--active' : '' ?>">None</a>
                    <?php foreach ($filterPillOptions['feed_categories'] as $cat): ?>
                        <?php $fcClass = ($cat === 'scraper') ? 'filter-pill--scraper' : 'filter-pill--feed'; ?>
                        <a href="?<?= e($toggleCsvQs('fc', $cat)) ?>"
                           class="filter-pill <?= e($fcClass) ?><?= $csvHas($fcRaw, $cat) ? ' filter-pill--active' : '' ?>"><?= e($cat) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if ($filterPillOptions['lex_sources'] !== []): ?>
                <div class="filter-toolbar__row">
                    <span class="filter-toolbar__hint">Lex</span>
                    <a href="?<?= e($dashboardQs(['lx' => null])) ?>"
                       class="filter-pill filter-pill--none<?= $lxRaw === '' ? ' filter-pill--active' : '' ?>">None</a>
                    <?php foreach ($filterPillOptions['lex_sources'] as $src): ?>
                        <a href="?<?= e($toggleCsvQs('lx', $src)) ?>"
                           class="filter-pill filter-pill--lex<?= $csvHas($lxRaw, $src) ? ' filter-pill--active' : '' ?>"><?= e($src) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if ($filterPillOptions['email_tags'] !== []): ?>
                <div class="filter-toolbar__row">
                    <span class="filter-toolbar__hint">Email tag</span>
                    <a href="?<?= e($dashboardQs(['etag' => null])) ?>"
                       class="filter-pill filter-pill--none<?= $etagRaw === '' ? ' filter-pill--active' : '' ?>">None</a>
                    <?php foreach ($filterPillOptions['email_tags'] as $tg): ?>
                        <a href="?<?= e($toggleCsvQs('etag', $tg)) ?>"
                           class="filter-pill filter-pill--mail<?= $csvHas($etagRaw, $tg) ? ' filter-pill--active' : '' ?>"><?= e($tg) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="filter-toolbar__row">
                    <span class="filter-toolbar__hint">Leg</span>
                    <a href="?<?= e($dashboardQs(['leg' => null])) ?>"
                       class="filter-pill filter-pill--none<?= !$legActive ? ' filter-pill--active' : '' ?>">None</a>
                    <a href="?<?= e($legToggleQs) ?>"
                       class="filter-pill filter-pill--leg<?= $legActive ? ' filter-pill--active' : '' ?>"
                       title="Show only Leg (parliamentary calendar) entries">Leg</a>
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
