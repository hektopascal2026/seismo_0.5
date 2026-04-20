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

        <div class="view-toggle view-toggle-bar">
            <span class="view-toggle-label">View:</span>
            <a href="<?= e($basePath) ?>/index.php?<?= e($itemsQs) ?>" class="btn <?= $view === 'items' ? 'btn-primary' : 'btn-secondary' ?>">Items</a>
            <a href="<?= e($basePath) ?>/index.php?<?= e($sourcesQs) ?>" class="btn <?= $view === 'sources' ? 'btn-primary' : 'btn-secondary' ?>">Feeds</a>
        </div>

        <?php if ($satellite && $view === 'sources'): ?>
            <p class="message message-info">Satellite mode: feed definitions are read-only here. Manage sources on the mothership.</p>
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
            <form method="post" action="<?= e($basePath) ?>/index.php?action=feed_save" class="admin-form-card">
                <?= $csrfField ?>
                <input type="hidden" name="id" value="<?= $editRow ? (int)$editRow['id'] : '' ?>">
                <h3><?= $editRow ? 'Edit feed' : 'Add feed' ?></h3>
                <div class="admin-form-field">
                    <label>URL / API endpoint <input type="text" name="url" required class="search-input" style="width:100%;" value="<?= e((string)($editRow['url'] ?? '')) ?>" placeholder="https://… (RSS) or SharePoint list URL for parl_press"></label>
                </div>
                <div class="admin-form-field">
                    <label>Title <input type="text" name="title" required class="search-input" style="width:100%;" value="<?= e((string)($editRow['title'] ?? '')) ?>"></label>
                </div>
                <div class="admin-form-field">
                    <label>Source type
                        <select name="source_type" class="search-input" style="width:100%;">
                            <?php $st = (string)($editRow['source_type'] ?? 'rss'); ?>
                            <option value="rss" <?= $st === 'rss' ? 'selected' : '' ?>>rss</option>
                            <option value="substack" <?= $st === 'substack' ? 'selected' : '' ?>>substack</option>
                            <option value="parl_press" <?= $st === 'parl_press' ? 'selected' : '' ?>>parl_press (Bundeshaus Medien)</option>
                        </select>
                    </label>
                </div>
                <?php if (($editRow['source_type'] ?? '') === 'parl_press'): ?>
                <div class="admin-help">
                    For <strong>parl_press</strong>, the URL is the SharePoint list <code>…/items</code> endpoint (same host as Medienmitteilungen). Put JSON in Description, e.g.
                    <code>{"lookback_days":90,"limit":50,"language":"de"}</code>
                    <br><br>
                    <strong>SDA-Meldungen</strong> (same <code>Pages</code> list, slug filter): use the same URL as press releases, then e.g.
                    <code>{"lookback_days":365,"limit":80,"language":"de","odata_title_substring":"sda-"}</code>
                    — with <code>odata_title_substring</code> set, <code>guid_prefix</code> defaults to <code>parl_sda</code> when omitted (explicit <code>"guid_prefix":"parl_sda"</code> is still fine). Set <strong>Category</strong> to <code>parl_sda</code> so the timeline shows the SDA pill even for older rows until the next refresh rewrites GUIDs. Use a generous <code>lookback_days</code> if few SDA pages fall in the window.
                </div>
                <?php endif; ?>
                <div class="admin-form-field">
                    <label>Description<br><textarea name="description" rows="2" class="search-input" style="width:100%;"><?= e((string)($editRow['description'] ?? '')) ?></textarea></label>
                </div>
                <div class="admin-form-field">
                    <label>Site link <input type="text" name="link" class="search-input" style="width:100%;" value="<?= e((string)($editRow['link'] ?? '')) ?>"></label>
                </div>
                <div class="admin-form-field">
                    <label>Category <input type="text" name="category" class="search-input" style="width:100%; max-width:24rem;" value="<?= e((string)($editRow['category'] ?? '')) ?>"></label>
                </div>
                <div class="admin-form-field">
                    <input type="hidden" name="disabled" value="0">
                    <label><input type="checkbox" name="disabled" value="1" <?= !empty($editRow['disabled']) ? 'checked' : '' ?>> Disabled</label>
                </div>
                <div class="admin-form-actions">
                    <button type="submit" class="btn btn-success"><?= $editRow ? 'Save' : 'Add feed' ?></button>
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
                        <th>Title</th>
                        <th>Type</th>
                        <th>URL</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($feedsList as $row): ?>
                    <tr>
                        <td><?= (int)$row['id'] ?></td>
                        <td><?= e((string)$row['title']) ?></td>
                        <td><?= e((string)($row['source_type'] ?? '')) ?></td>
                        <td style="word-break:break-all;"><a href="<?= e((string)$row['url']) ?>" target="_blank" rel="noopener"><?= e((string)$row['url']) ?></a></td>
                        <td>
                            <?php if (!$satellite): ?>
                            <div class="admin-table-actions">
                                <a href="<?= e($basePath) ?>/index.php?action=feeds&amp;view=sources&amp;edit=<?= (int)$row['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                                <form method="post" action="<?= e($basePath) ?>/index.php?action=feed_delete" class="admin-inline-form" onsubmit="return confirm('Delete this feed and its items?');">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            </div>
                            <?php else: ?>
                            <span style="opacity:0.6;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($feedsList === []): ?>
                    <tr class="data-table-empty"><td colspan="5">No feeds defined.</td></tr>
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
