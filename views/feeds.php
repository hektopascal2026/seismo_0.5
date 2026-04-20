<?php
/**
 * RSS / Substack feeds — Items | Feeds (Slice 8).
 *
 * @var array<int, array<string, mixed>> $allItems
 * @var list<array<string, mixed>> $feedsList
 * @var ?array<string, mixed> $editRow
 * @var ?string $pageError
 * @var string $csrfField
 * @var float $alertThreshold
 * @var string $view 'items'|'sources'
 * @var bool $satellite
 * @var string $basePath
 * @var ?string $dashboardError
 */

declare(strict_types=1);

$basePath = getBasePath();
$accent     = seismoBrandAccent();

$headerTitle    = 'Feeds';
$headerSubtitle = 'RSS & Substack';
$activeNav      = 'feeds';

$itemsQs   = 'action=feeds';
$sourcesQs = 'action=feeds&view=sources';
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

        <div class="view-toggle" style="margin-bottom: 1rem; display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
            <span style="opacity: 0.85;">View:</span>
            <a href="<?= e($basePath) ?>/index.php?<?= e($itemsQs) ?>" class="btn <?= $view === 'items' ? 'btn-primary' : 'btn-secondary' ?>">Items</a>
            <a href="<?= e($basePath) ?>/index.php?<?= e($sourcesQs) ?>" class="btn <?= $view === 'sources' ? 'btn-primary' : 'btn-secondary' ?>">Feeds</a>
        </div>

        <?php if ($satellite && $view === 'sources'): ?>
            <p class="message message-error">Satellite mode: feed definitions are read-only here. Manage sources on the mothership.</p>
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
                    <p>No RSS or Substack items yet. Add a feed under <a href="<?= e($basePath) ?>/index.php?<?= e($sourcesQs) ?>">Feeds</a> or run a refresh from Diagnostics.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="latest-entries-section">
            <h2 class="section-title">Feed sources</h2>

            <?php if (!$satellite): ?>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=feed_save" style="max-width:640px; margin-bottom:24px; padding:16px; border:1px solid #ccc; background:#fafafa;">
                <?= $csrfField ?>
                <input type="hidden" name="id" value="<?= $editRow ? (int)$editRow['id'] : '' ?>">
                <h3 style="margin-bottom:12px;"><?= $editRow ? 'Edit feed' : 'Add feed' ?></h3>
                <div style="margin-bottom:8px;">
                    <label>URL / API endpoint <input type="text" name="url" required class="search-input" style="width:100%;" value="<?= e((string)($editRow['url'] ?? '')) ?>" placeholder="https://… (RSS) or SharePoint list URL for parl_press"></label>
                </div>
                <div style="margin-bottom:8px;">
                    <label>Title <input type="text" name="title" required class="search-input" style="width:100%;" value="<?= e((string)($editRow['title'] ?? '')) ?>"></label>
                </div>
                <div style="margin-bottom:8px;">
                    <label>Source type
                        <select name="source_type">
                            <?php $st = (string)($editRow['source_type'] ?? 'rss'); ?>
                            <option value="rss" <?= $st === 'rss' ? 'selected' : '' ?>>rss</option>
                            <option value="substack" <?= $st === 'substack' ? 'selected' : '' ?>>substack</option>
                            <option value="parl_press" <?= $st === 'parl_press' ? 'selected' : '' ?>>parl_press (Bundeshaus Medien)</option>
                        </select>
                    </label>
                </div>
                <?php if (($editRow['source_type'] ?? '') === 'parl_press'): ?>
                <div style="margin-bottom:8px; font-size:12px; color:#555;">
                    For <strong>parl_press</strong>, the URL is the SharePoint list endpoint. Put JSON in Description, e.g.
                    <code style="word-break:break-all;">{"lookback_days":90,"limit":50,"language":"de"}</code>
                </div>
                <?php endif; ?>
                <div style="margin-bottom:8px;">
                    <label>Description<br><textarea name="description" rows="2" style="width:100%;"><?= e((string)($editRow['description'] ?? '')) ?></textarea></label>
                </div>
                <div style="margin-bottom:8px;">
                    <label>Site link <input type="text" name="link" class="search-input" style="width:100%;" value="<?= e((string)($editRow['link'] ?? '')) ?>"></label>
                </div>
                <div style="margin-bottom:8px;">
                    <label>Category <input type="text" name="category" class="search-input" value="<?= e((string)($editRow['category'] ?? '')) ?>"></label>
                </div>
                <div style="margin-bottom:12px;">
                    <input type="hidden" name="disabled" value="0">
                    <label><input type="checkbox" name="disabled" value="1" <?= !empty($editRow['disabled']) ? 'checked' : '' ?>> Disabled</label>
                </div>
                <button type="submit" class="btn btn-primary"><?= $editRow ? 'Save' : 'Add feed' ?></button>
                <?php if ($editRow): ?>
                    <a href="<?= e($basePath) ?>/index.php?<?= e($sourcesQs) ?>" class="btn btn-secondary">Cancel edit</a>
                <?php endif; ?>
            </form>
            <?php endif; ?>

            <table class="data-table" style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="text-align:left; border-bottom:1px solid #000;">
                        <th style="padding:6px;">ID</th>
                        <th style="padding:6px;">Title</th>
                        <th style="padding:6px;">Type</th>
                        <th style="padding:6px;">URL</th>
                        <th style="padding:6px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($feedsList as $row): ?>
                    <tr style="border-bottom:1px solid #ddd;">
                        <td style="padding:6px;"><?= (int)$row['id'] ?></td>
                        <td style="padding:6px;"><?= e((string)$row['title']) ?></td>
                        <td style="padding:6px;"><?= e((string)($row['source_type'] ?? '')) ?></td>
                        <td style="padding:6px; word-break:break-all;"><a href="<?= e((string)$row['url']) ?>" target="_blank" rel="noopener"><?= e((string)$row['url']) ?></a></td>
                        <td style="padding:6px; white-space:nowrap;">
                            <?php if (!$satellite): ?>
                            <a href="<?= e($basePath) ?>/index.php?action=feeds&amp;view=sources&amp;edit=<?= (int)$row['id'] ?>" class="btn btn-secondary" style="font-size:12px; padding:2px 8px;">Edit</a>
                            <form method="post" action="<?= e($basePath) ?>/index.php?action=feed_delete" style="display:inline;" onsubmit="return confirm('Delete this feed and its items?');">
                                <?= $csrfField ?>
                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                <button type="submit" class="btn btn-secondary" style="font-size:12px; padding:2px 8px;">Delete</button>
                            </form>
                            <?php else: ?>
                            <span style="opacity:0.6;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($feedsList === []): ?>
                    <tr><td colspan="5" style="padding:12px; opacity:0.8;">No feeds defined.</td></tr>
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
