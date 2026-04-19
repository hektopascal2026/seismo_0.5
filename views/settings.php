<?php
/**
 * Settings — tabs: General, Retention.
 *
 * @var string $tab 'general'|'retention'
 * @var string $csrfField
 * @var string $basePath
 * @var int $dashboardLimitSaved
 * @var ?string $pageError
 * @var array<string, mixed> $rows
 * @var array<string, mixed> $defaults
 * @var list<string> $families
 * @var bool $satellite
 */

declare(strict_types=1);

if (!function_exists('e')) {
    require_once __DIR__ . '/helpers.php';
}

$accent = seismoBrandAccent();
$headerTitle = 'Settings';
$headerSubtitle = 'Preferences & data retention';
$activeNav = 'settings';
$dashboardLimitMax = \Seismo\Repository\EntryRepository::MAX_LIMIT;

$tabQs = static function (string $t) use ($basePath): string {
    return $basePath . '/index.php?' . http_build_query(['action' => 'settings', 'tab' => $t]);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — <?= e(seismoBrandTitle()) ?></title>
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css">
    <?php if ($accent): ?>
    <style>:root { --seismo-accent: <?= e($accent) ?>; }</style>
    <?php endif; ?>
    <style>
        .settings-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px; }
        .settings-tabs a { padding: 8px 14px; text-decoration: none; font-weight: 600; border: 2px solid #000; color: #000; background: #fff; }
        .settings-tabs a:hover { box-shadow: 2px 2px 0 #000; }
        .settings-tabs a.active { background: var(--seismo-accent, #000); color: #fff; border-color: #000; }
        .retention-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .retention-table th, .retention-table td { padding: 8px 10px; border-bottom: 1px solid #e5e5e5; text-align: left; vertical-align: top; }
        .retention-table th { background: #fafafa; font-weight: 600; }
        .retention-table input[type="number"] { width: 90px; padding: 4px 6px; }
        .retention-keeps label { display: block; font-size: 0.9em; margin-top: 2px; }
        .retention-unlimited { color: #666; font-style: italic; }
    </style>
</head>
<body>
    <div class="container">
        <?php
        require __DIR__ . '/partials/site_header.php';
        ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="message message-success"><?= e((string)$_SESSION['success']) ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="message message-error"><?= e((string)$_SESSION['error']) ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <nav class="settings-tabs" aria-label="Settings sections">
            <a href="<?= e($tabQs('general')) ?>" class="<?= $tab === 'general' ? 'active' : '' ?>">General</a>
            <a href="<?= e($tabQs('retention')) ?>" class="<?= $tab === 'retention' ? 'active' : '' ?>">Retention</a>
        </nav>

        <?php if ($tab === 'general'): ?>
            <?php require __DIR__ . '/partials/settings_general.php'; ?>
        <?php else: ?>
            <?php if ($pageError !== null): ?>
                <div class="message message-error"><?= e($pageError) ?></div>
            <?php endif; ?>
            <?php if ($satellite): ?>
                <p class="message message-error">Satellite mode — entry tables live on the mothership. Policies here are for reference; pruning runs on the mothership only.</p>
            <?php endif; ?>
            <?php require __DIR__ . '/partials/retention_panel.php'; ?>
        <?php endif; ?>
    </div>
</body>
</html>
