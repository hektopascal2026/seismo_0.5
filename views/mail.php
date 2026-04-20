<?php
/**
 * Email timeline — Items | Subscriptions (Slice 8).
 *
 * @var array<int, array<string, mixed>> $allItems
 * @var list<array<string, mixed>> $subscriptions
 * @var ?array<string, mixed> $editRow
 * @var ?string $pageError
 * @var string $csrfField
 * @var float $alertThreshold
 * @var string $view 'items'|'subscriptions'
 * @var bool $satellite
 * @var ?string $dashboardError
 */

declare(strict_types=1);

$basePath = getBasePath();
$accent     = seismoBrandAccent();

$headerTitle    = 'Mail';
$headerSubtitle = 'IMAP / newsletter';
$activeNav      = 'mail';

$itemsQs          = 'action=mail';
$subscriptionsQs = 'action=mail&view=subscriptions';
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
            <a href="<?= e($basePath) ?>/index.php?<?= e($subscriptionsQs) ?>" class="btn <?= $view === 'subscriptions' ? 'btn-primary' : 'btn-secondary' ?>">Subscriptions</a>
        </div>

        <?php if ($satellite && $view === 'subscriptions'): ?>
            <p class="message message-error">Satellite mode: subscriptions are read-only here. Manage them on the mothership.</p>
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
                    <p>No email rows yet. Configure IMAP fetch separately; subscription rules live under <a href="<?= e($basePath) ?>/index.php?<?= e($subscriptionsQs) ?>">Subscriptions</a>.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="latest-entries-section">
            <h2 class="section-title">Email subscriptions</h2>
            <p style="margin-bottom:12px; opacity:0.9;">Domain-first matching (e.g. <code>example.com</code> covers <code>alice@example.com</code>). Per-address overrides use match type <em>email</em>. <code>show_in_magnitu</code> is stored for future pipeline use — the Magnitu export API does not filter on it yet.</p>

            <?php if (!$satellite): ?>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=mail_subscription_save" style="max-width:640px; margin-bottom:24px; padding:16px; border:1px solid #ccc; background:#fafafa;">
                <?= $csrfField ?>
                <input type="hidden" name="id" value="<?= $editRow ? (int)$editRow['id'] : '' ?>">
                <h3 style="margin-bottom:12px;"><?= $editRow ? 'Edit subscription' : 'Add subscription' ?></h3>
                <div style="margin-bottom:8px;">
                    <label>Match type
                        <?php $mt = (string)($editRow['match_type'] ?? 'domain'); ?>
                        <select name="match_type">
                            <option value="domain" <?= $mt === 'domain' ? 'selected' : '' ?>>domain</option>
                            <option value="email" <?= $mt === 'email' ? 'selected' : '' ?>>email</option>
                        </select>
                    </label>
                </div>
                <div style="margin-bottom:8px;">
                    <label>Match value <input type="text" name="match_value" required class="search-input" style="width:100%;" value="<?= e((string)($editRow['match_value'] ?? '')) ?>" placeholder="example.com or user@example.com"></label>
                </div>
                <div style="margin-bottom:8px;">
                    <label>Display name <input type="text" name="display_name" class="search-input" style="width:100%;" value="<?= e((string)($editRow['display_name'] ?? '')) ?>"></label>
                </div>
                <div style="margin-bottom:8px;">
                    <label>Category <input type="text" name="category" class="search-input" value="<?= e((string)($editRow['category'] ?? '')) ?>"></label>
                </div>
                <div style="margin-bottom:8px;">
                    <input type="hidden" name="disabled" value="0">
                    <label><input type="checkbox" name="disabled" value="1" <?= !empty($editRow['disabled']) ? 'checked' : '' ?>> Disabled</label>
                </div>
                <div style="margin-bottom:8px;">
                    <input type="hidden" name="show_in_magnitu" value="0">
                    <label><input type="checkbox" name="show_in_magnitu" value="1" <?= ($editRow === null || !isset($editRow['show_in_magnitu']) || !empty($editRow['show_in_magnitu'])) ? 'checked' : '' ?>> Show in Magnitu (stored preference)</label>
                </div>
                <div style="margin-bottom:8px;">
                    <label>Unsubscribe URL <input type="url" name="unsubscribe_url" class="search-input" style="width:100%;" value="<?= e((string)($editRow['unsubscribe_url'] ?? '')) ?>"></label>
                </div>
                <div style="margin-bottom:8px;">
                    <label>Unsubscribe mailto <input type="text" name="unsubscribe_mailto" class="search-input" style="width:100%;" value="<?= e((string)($editRow['unsubscribe_mailto'] ?? '')) ?>"></label>
                </div>
                <div style="margin-bottom:12px;">
                    <input type="hidden" name="unsubscribe_one_click" value="0">
                    <label><input type="checkbox" name="unsubscribe_one_click" value="1" <?= !empty($editRow['unsubscribe_one_click']) ? 'checked' : '' ?>> One-click unsubscribe</label>
                </div>
                <button type="submit" class="btn btn-primary"><?= $editRow ? 'Save' : 'Add subscription' ?></button>
                <?php if ($editRow): ?>
                    <a href="<?= e($basePath) ?>/index.php?<?= e($subscriptionsQs) ?>" class="btn btn-secondary">Cancel edit</a>
                <?php endif; ?>
            </form>
            <?php endif; ?>

            <table class="data-table" style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="text-align:left; border-bottom:1px solid #000;">
                        <th style="padding:6px;">ID</th>
                        <th style="padding:6px;">Match</th>
                        <th style="padding:6px;">Name</th>
                        <th style="padding:6px;">Disabled</th>
                        <th style="padding:6px;">Magnitu</th>
                        <th style="padding:6px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($subscriptions as $row): ?>
                    <tr style="border-bottom:1px solid #ddd;">
                        <td style="padding:6px;"><?= (int)$row['id'] ?></td>
                        <td style="padding:6px;"><?= e((string)$row['match_type']) ?>: <?= e((string)$row['match_value']) ?></td>
                        <td style="padding:6px;"><?= e((string)$row['display_name']) ?></td>
                        <td style="padding:6px;"><?= !empty($row['disabled']) ? 'yes' : 'no' ?></td>
                        <td style="padding:6px;"><?= !isset($row['show_in_magnitu']) || !empty($row['show_in_magnitu']) ? 'on' : 'off' ?></td>
                        <td style="padding:6px; white-space:nowrap;">
                            <?php if (!$satellite): ?>
                            <a href="<?= e($basePath) ?>/index.php?action=mail&amp;view=subscriptions&amp;edit=<?= (int)$row['id'] ?>" class="btn btn-secondary" style="font-size:12px; padding:2px 8px;">Edit</a>
                            <form method="post" action="<?= e($basePath) ?>/index.php?action=mail_subscription_disable" style="display:inline;">
                                <?= $csrfField ?>
                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                <button type="submit" class="btn btn-secondary" style="font-size:12px; padding:2px 8px;" title="Disable">Unsubscribe</button>
                            </form>
                            <form method="post" action="<?= e($basePath) ?>/index.php?action=mail_subscription_delete" style="display:inline;" onsubmit="return confirm('Remove this subscription row?');">
                                <?= $csrfField ?>
                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                <button type="submit" class="btn btn-secondary" style="font-size:12px; padding:2px 8px;">Remove</button>
                            </form>
                            <?php else: ?>
                            <span style="opacity:0.6;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($subscriptions === []): ?>
                    <tr><td colspan="6" style="padding:12px; opacity:0.8;">No subscriptions.</td></tr>
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
