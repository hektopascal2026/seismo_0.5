<?php
/**
 * @var string $csrfField
 * @var string $basePath
 */

declare(strict_types=1);

$accent = seismoBrandAccent();
$headerTitle = 'Styleguide';
$headerSubtitle = 'Typography, buttons, cards';
$activeNav = 'styleguide';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Styleguide — <?= e(seismoBrandTitle()) ?></title>
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css">
    <?php if ($accent): ?>
    <style>:root { --seismo-accent: <?= e($accent) ?>; }</style>
    <?php endif; ?>
</head>
<body>
    <div class="container">
        <?php require __DIR__ . '/partials/site_header.php'; ?>

        <h2 class="section-title">Typography</h2>
        <p class="admin-intro">Body uses the system stack at 14px. <span class="type-sample-big">Big (18px)</span> for titles. <span class="type-sample-small">Small (12px)</span> for meta.</p>

        <h2 class="section-title module-section-spaced">Buttons</h2>
        <p class="admin-form-actions">
            <a href="#" class="btn btn-primary" onclick="return false;">Primary</a>
            <a href="#" class="btn btn-secondary" onclick="return false;">Secondary</a>
            <a href="#" class="btn btn-success" onclick="return false;">Success</a>
            <a href="#" class="btn btn-warning" onclick="return false;">Warning</a>
            <a href="#" class="btn btn-danger" onclick="return false;">Danger</a>
        </p>

        <h2 class="section-title module-section-spaced">Messages</h2>
        <div class="message message-success">Success — 12px, 2px border</div>
        <div class="message message-error">Error</div>
        <div class="message message-info">Info</div>

        <h2 class="section-title module-section-spaced">Entry card sample</h2>
        <div class="entry-card">
            <div class="entry-header">
                <span class="entry-tag entry-tag--meta">feed_item</span>
                <span class="entry-tag">investigation_lead</span>
            </div>
            <div class="entry-content">
                <p>Card body — expand/collapse pattern matches the dashboard.</p>
            </div>
        </div>

        <p class="admin-intro module-section-spaced">Shared with Magnitu tooling; keep components aligned when changing <code>assets/css/style.css</code>.</p>
    </div>
</body>
</html>
