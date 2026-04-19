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
        <p>Body uses the system stack at 14px. <span style="font-size:18px;font-weight:700;">Big (18px)</span> for titles. <span style="font-size:12px;">Small (12px)</span> for meta.</p>

        <h2 class="section-title" style="margin-top:24px;">Buttons</h2>
        <p style="margin-bottom:10px;">
            <a href="#" class="btn btn-primary" onclick="return false;">Primary</a>
            <a href="#" class="btn btn-secondary" onclick="return false;">Secondary</a>
        </p>

        <h2 class="section-title" style="margin-top:24px;">Entry card sample</h2>
        <div class="entry-card">
            <div class="entry-header">
                <span class="entry-tag" style="background:#f5f5f5;">feed_item</span>
                <span class="entry-tag">investigation_lead</span>
            </div>
            <div class="entry-content" style="margin-top:8px;">
                <p>Card body — expand/collapse pattern matches the dashboard.</p>
            </div>
        </div>

        <p style="margin-top:24px; color:#555; font-size:12px;">Shared with Magnitu tooling; keep components aligned when changing <code>assets/css/style.css</code>.</p>
    </div>
</body>
</html>
