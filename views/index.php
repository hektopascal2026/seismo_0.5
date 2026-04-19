<?php
/**
 * Dashboard / timeline view.
 *
 * Slice 1.5: search (GET), newest/favourites toggle, star buttons, session flash.
 * Tag-filter pills, refresh, and nav drawer remain future slices.
 */

declare(strict_types=1);

/** @var array<int, array<string, mixed>> $allItems */
/** @var string $searchQuery */
/** @var bool $showDaySeparators */
/** @var bool $showFavourites */
/** @var string $returnQuery */
/** @var ?string $dashboardError */
/** @var string $currentView 'newest'|'favourites' */
/** @var string $emptyTimelineHint 'default'|'favourites'|'search' */
/** @var string $csrfField CSRF hidden input HTML from DashboardController::show() */

use Seismo\Http\AuthGate;

$basePath = getBasePath();
$accent   = seismoBrandAccent();

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
