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

use Seismo\Http\AuthGate;

$basePath = getBasePath();
$accent   = seismoBrandAccent();

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
        <div class="top-bar">
            <div class="top-bar-left">
                <span class="top-bar-title">
                    <a href="?action=index">
                        <svg class="logo-icon logo-icon-large" viewBox="0 0 24 16" xmlns="http://www.w3.org/2000/svg">
                            <rect width="24" height="16" fill="#FFFFC5"/>
                            <path d="M0,8 L4,12 L6,4 L10,10 L14,2 L18,8 L20,6 L24,8" stroke="#000000" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                    <?= e(seismoBrandTitle()) ?>
                </span>
                <?php if (!isSatellite()): ?>
                <span class="top-bar-subtitle">ein Prototyp von hektopascal.org | v<?= e(SEISMO_VERSION) ?></span>
                <?php endif; ?>
            </div>
            <div class="top-bar-actions">
                <a href="<?= e($basePath) ?>/index.php?action=lex" class="top-bar-btn" title="Lex">Lex</a>
                <a href="<?= e($basePath) ?>/index.php?action=leg" class="top-bar-btn" title="Leg">Leg</a>
                <a href="<?= e($basePath) ?>/index.php?action=diagnostics" class="top-bar-btn" title="Diagnostics">Diag</a>
                <?php if (AuthGate::isEnabled() && AuthGate::isLoggedIn()): ?>
                    <form method="post" action="<?= e($basePath) ?>/index.php?action=logout" style="display:inline; margin:0;">
                        <?= $csrfField ?>
                        <button type="submit" class="top-bar-btn" title="Sign out">Logout</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

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

        <div class="search-section" style="margin-bottom: 1rem;">
            <form method="get" class="search-form" style="display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center;">
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
            <div class="view-toggle" style="margin-top: 0.75rem; display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
                <span style="opacity: 0.85; margin-right: 0.25rem;">View:</span>
                <a href="?<?= e($indexNewestQs) ?>" class="btn <?= $currentView === 'newest' ? 'btn-primary' : 'btn-secondary' ?>">Newest</a>
                <a href="?<?= e($indexFavouritesQs) ?>" class="btn <?= $currentView === 'favourites' ? 'btn-primary' : 'btn-secondary' ?>">Favourites</a>
            </div>
            <?php
                $fk = isset($_GET['fk']) ? (string)$_GET['fk'] : '';
                $fc = isset($_GET['fc']) ? (string)$_GET['fc'] : '';
                $lx = isset($_GET['lx']) ? (string)$_GET['lx'] : '';
                $etag = isset($_GET['etag']) ? (string)$_GET['etag'] : '';
                $nocal = isset($_GET['nocal']) && (string)$_GET['nocal'] === '1';
            ?>
            <div class="tag-pills-section" style="margin-top: 1rem;">
                <div style="opacity: 0.85; margin-bottom: 0.35rem;">Filters:</div>
                <div style="display: flex; flex-wrap: wrap; gap: 0.35rem; align-items: center;">
                    <a href="?<?= e($dashboardQs(['fc' => null, 'fk' => null, 'lx' => null, 'etag' => null, 'nocal' => null])) ?>" class="btn btn-secondary" style="font-size: 0.85rem; padding: 0.2rem 0.5rem;">Clear filters</a>
                    <span style="opacity: 0.7;">|</span>
                    <span style="opacity: 0.75;">Feed type:</span>
                    <a href="?<?= e($dashboardQs(['fk' => 'rss'])) ?>" class="btn <?= $fk === 'rss' ? 'btn-primary' : 'btn-secondary' ?>" style="font-size: 0.85rem; padding: 0.2rem 0.5rem;">RSS</a>
                    <a href="?<?= e($dashboardQs(['fk' => 'substack'])) ?>" class="btn <?= $fk === 'substack' ? 'btn-primary' : 'btn-secondary' ?>" style="font-size: 0.85rem; padding: 0.2rem 0.5rem;">Substack</a>
                    <a href="?<?= e($dashboardQs(['fk' => 'scraper'])) ?>" class="btn <?= $fk === 'scraper' ? 'btn-primary' : 'btn-secondary' ?>" style="font-size: 0.85rem; padding: 0.2rem 0.5rem;">Scraper</a>
                    <span style="opacity: 0.7;">|</span>
                    <span style="opacity: 0.75;">Leg in timeline:</span>
                    <a href="?<?= e($dashboardQs(['nocal' => $nocal ? null : '1'])) ?>" class="btn <?= $nocal ? 'btn-secondary' : 'btn-primary' ?>" style="font-size: 0.85rem; padding: 0.2rem 0.5rem;"><?= $nocal ? 'Off' : 'On' ?></a>
                </div>
                <?php if ($filterPillOptions['feed_categories'] !== []): ?>
                <div style="display: flex; flex-wrap: wrap; gap: 0.35rem; align-items: center; margin-top: 0.5rem;">
                    <span style="opacity: 0.75;">Feed category:</span>
                    <?php foreach ($filterPillOptions['feed_categories'] as $cat): ?>
                        <a href="?<?= e($dashboardQs(['fc' => $cat, 'fk' => null])) ?>" class="btn <?= $fc === $cat ? 'btn-primary' : 'btn-secondary' ?>" style="font-size: 0.85rem; padding: 0.2rem 0.5rem;"><?= e($cat) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if ($filterPillOptions['lex_sources'] !== []): ?>
                <div style="display: flex; flex-wrap: wrap; gap: 0.35rem; align-items: center; margin-top: 0.5rem;">
                    <span style="opacity: 0.75;">Lex:</span>
                    <?php foreach ($filterPillOptions['lex_sources'] as $src): ?>
                        <a href="?<?= e($dashboardQs(['lx' => $src])) ?>" class="btn <?= $lx === $src ? 'btn-primary' : 'btn-secondary' ?>" style="font-size: 0.85rem; padding: 0.2rem 0.5rem;"><?= e($src) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if ($filterPillOptions['email_tags'] !== []): ?>
                <div style="display: flex; flex-wrap: wrap; gap: 0.35rem; align-items: center; margin-top: 0.5rem;">
                    <span style="opacity: 0.75;">Email tag:</span>
                    <?php foreach ($filterPillOptions['email_tags'] as $tg): ?>
                        <a href="?<?= e($dashboardQs(['etag' => $tg])) ?>" class="btn <?= $etag === $tg ? 'btn-primary' : 'btn-secondary' ?>" style="font-size: 0.85rem; padding: 0.2rem 0.5rem;"><?= e($tg) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
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
                        <p>No entries match the current filters. <a href="?<?= e($dashboardQs(['fc' => null, 'fk' => null, 'lx' => null, 'etag' => null, 'nocal' => null])) ?>">Clear filters</a> or widen the selection.</p>
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
