<?php
/**
 * Scraper feed items — Items | Sources (Slice 8).
 *
 * @var array<int, array<string, mixed>> $allItems
 * @var list<array<string, mixed>> $configsList
 * @var ?array<string, mixed> $editRow
 * @var ?string $pageError
 * @var string $csrfField
 * @var float $alertThreshold
 * @var string $view 'items'|'sources'
 * @var bool $satellite
 * @var ?string $dashboardError
 */

declare(strict_types=1);

$basePath = getBasePath();
$accent     = seismoBrandAccent();

$headerTitle    = 'Scraper';
$headerSubtitle = 'Scraped pages';
$activeNav      = 'scraper';

$itemsQs   = 'action=scraper';
$sourcesQs = 'action=scraper&view=sources';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($headerTitle) ?> — <?= e(seismoBrandTitle()) ?></title>
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

        <div class="view-toggle view-toggle-bar">
            <span class="view-toggle-label">View:</span>
            <a href="<?= e($basePath) ?>/index.php?<?= e($itemsQs) ?>" class="btn <?= $view === 'items' ? 'btn-primary' : 'btn-secondary' ?>">Items</a>
            <a href="<?= e($basePath) ?>/index.php?<?= e($sourcesQs) ?>" class="btn <?= $view === 'sources' ? 'btn-primary' : 'btn-secondary' ?>">Sources</a>
        </div>

        <?php if ($satellite && $view === 'sources'): ?>
            <p class="message message-info">Satellite mode: scraper targets are read-only here. Manage sources on the mothership.</p>
        <?php endif; ?>

        <?php if ($pageError !== null): ?>
            <div class="message message-error"><?= e($pageError) ?></div>
        <?php endif; ?>

        <?php if ($view === 'items'): ?>
        <div class="latest-entries-section">
            <div class="section-title-row">
                <h2 class="section-title">
                    <?= count($allItems) ?> <?= count($allItems) === 1 ? 'entry' : 'entries' ?>
                </h2>
                <button class="btn btn-secondary entry-expand-all-btn">expand all &#9660;</button>
            </div>
            <?php if ($dashboardError !== null): ?>
            <?php elseif ($allItems !== []): ?>
                <?php include __DIR__ . '/partials/dashboard_entry_loop.php'; ?>
            <?php else: ?>
                <div class="empty-state">
                    <p>No scraper items yet. Add a source under <a href="<?= e($basePath) ?>/index.php?<?= e($sourcesQs) ?>">Sources</a> or run refresh from Diagnostics.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="latest-entries-section">
            <h2 class="section-title">Scraper sources</h2>
            <p class="admin-intro">Targets must match a row in <code>feeds</code> (same URL) for the core pipeline to store items. Plain RSS feeds belong on <a href="<?= e($basePath) ?>/index.php?action=feeds&amp;view=sources">Feeds</a>.</p>

            <?php if (!$satellite): ?>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=scraper_save" class="admin-form-card">
                <?= $csrfField ?>
                <input type="hidden" name="id" value="<?= $editRow ? (int)$editRow['id'] : '' ?>">
                <h3><?= $editRow ? 'Edit source' : 'Add source' ?></h3>
                <div class="admin-form-field">
                    <label>Name <input type="text" name="name" required class="search-input" style="width:100%;" value="<?= e((string)($editRow['name'] ?? '')) ?>"></label>
                </div>
                <div class="admin-form-field">
                    <label>Page URL <input type="url" name="url" required class="search-input" style="width:100%;" value="<?= e((string)($editRow['url'] ?? '')) ?>" placeholder="https://…"></label>
                </div>
                <div class="admin-form-field">
                    <label>Link pattern (regex) <input type="text" name="link_pattern" class="search-input" style="width:100%;" value="<?= e((string)($editRow['link_pattern'] ?? '')) ?>"></label>
                </div>
                <div class="admin-form-field">
                    <label>Date selector (CSS) <input type="text" name="date_selector" class="search-input" style="width:100%;" value="<?= e((string)($editRow['date_selector'] ?? '')) ?>"></label>
                </div>
                <div class="admin-form-field">
                    <label>Category <input type="text" name="category" class="search-input" style="width:100%; max-width:24rem;" value="<?= e((string)($editRow['category'] ?? 'scraper')) ?>"></label>
                </div>
                <div class="admin-form-field">
                    <input type="hidden" name="disabled" value="0">
                    <label><input type="checkbox" name="disabled" value="1" <?= !empty($editRow['disabled']) ? 'checked' : '' ?>> Disabled</label>
                </div>
                <div class="admin-form-actions">
                    <button type="submit" class="btn btn-success"><?= $editRow ? 'Save' : 'Add source' ?></button>
                    <?php if ($editRow): ?>
                        <a href="<?= e($basePath) ?>/index.php?<?= e($sourcesQs) ?>" class="btn btn-secondary">Cancel edit</a>
                    <?php endif; ?>
                </div>
            </form>
            <?php endif; ?>

            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>URL</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($configsList as $row): ?>
                    <tr>
                        <td><?= (int)$row['id'] ?></td>
                        <td><?= e((string)$row['name']) ?></td>
                        <td class="data-table-url"><a href="<?= e((string)$row['url']) ?>" target="_blank" rel="noopener"><?= e((string)$row['url']) ?></a></td>
                        <td>
                            <?php if (!$satellite): ?>
                            <div class="admin-table-actions">
                                <a href="<?= e($basePath) ?>/index.php?action=scraper&amp;view=sources&amp;edit=<?= (int)$row['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                                <form method="post" action="<?= e($basePath) ?>/index.php?action=scraper_delete" class="admin-inline-form" onsubmit="return confirm('Delete this scraper config?');">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            </div>
                            <?php else: ?>
                            <span class="table-cell-placeholder">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($configsList === []): ?>
                    <tr class="data-table-empty"><td colspan="4">No scraper configs.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
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
