<?php
/**
 * Diagnostics — plugin run status surface.
 *
 * @var array<string, array{
 *     id: string,
 *     label: string,
 *     entry_type: string,
 *     config_key: string,
 *     min_interval: int,
 *     last: ?array{status: string, run_at: \DateTimeImmutable, item_count: int, error_message: ?string, duration_ms: int},
 *     next_allowed: ?\DateTimeImmutable,
 *     is_throttled: bool,
 * }> $status
 * @var array<string, array<string, mixed>> $coreStatus
 * @var ?string $loadError
 * @var ?array{id: string, count: int, error: ?string, items: list<array<string, mixed>>} $testResult
 * @var string $basePath
 * @var bool $satellite
 * @var array<string, list<array{run_at: \DateTimeImmutable, status: string, item_count: int, error_message: ?string, duration_ms: int}>> $runHistory
 */

declare(strict_types=1);

use Seismo\Http\CsrfToken;

if (!function_exists('seismo_format_utc')) {
    require_once __DIR__ . '/helpers.php';
}

$accent = seismoBrandAccent();

$statusBg = static function (?array $row): string {
    if ($row === null) {
        return '#f5f5f5';
    }
    return match ($row['status']) {
        'ok'      => '#d4edda',
        'error'   => '#ffcccc',
        'skipped' => '#f5f5f5',
        default   => '#fff3cd',
    };
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnostics — <?= e(seismoBrandTitle()) ?></title>
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css">
    <?php if ($accent): ?>
    <style>:root { --seismo-accent: <?= e($accent) ?>; }</style>
    <?php endif; ?>
</head>
<body>
    <div class="container">
        <?php
        $headerTitle = 'Diagnostics';
        $headerSubtitle = 'Plugin runs & refresh';
        $activeNav = 'diagnostics';
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
        <?php if ($loadError !== null): ?>
            <div class="message message-error"><?= e($loadError) ?></div>
        <?php endif; ?>
        <?php if ($satellite): ?>
            <p class="message message-error">Satellite mode: plugins do not run on this instance. The mothership refreshes the entry tables.</p>
        <?php endif; ?>

        <div class="latest-entries-section" style="margin-bottom: 24px;">
            <h2 class="section-title">Master refresh</h2>
            <p style="margin: 0 0 12px; color: #555;">
                Runs every registered plugin now, ignoring throttle. The CLI cron
                <code>refresh_cron.php</code> calls the same code with throttle on.
            </p>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=refresh_all" style="display:inline;">
                <?= CsrfToken::field() ?>
                <button type="submit" class="btn btn-primary"<?= $satellite ? ' disabled' : '' ?>>Refresh all now</button>
            </form>
        </div>

        <?php if ($coreStatus !== []): ?>
        <div class="latest-entries-section" style="margin-bottom: 24px;">
            <h2 class="section-title">Core fetchers (<?= count($coreStatus) ?>)</h2>
            <p style="margin: 0 0 12px; color: #555;">RSS (incl. Substack), Parliament press (<code>core:parl_press</code>), scraper, and mail runs share <code>plugin_run_log</code> under synthetic ids (<code>core:*</code>). They run automatically with “Refresh all now” and CLI cron.</p>
            <?php foreach ($coreStatus as $id => $s): ?>
                <?php
                    $bg = $statusBg($s['last']);
                    $lastStatus = $s['last']['status'] ?? 'never run';
                    $lastWhen   = $s['last'] !== null ? seismo_format_utc($s['last']['run_at']) : null;
                    $nextWhen   = $s['next_allowed'] !== null ? seismo_format_utc($s['next_allowed']) : null;
                ?>
                <div class="entry-card" style="background-color: <?= e($bg) ?>;">
                    <div class="entry-header">
                        <span class="entry-tag" style="background-color: #fff;">
                            <strong><?= e($s['label']) ?></strong>
                            <span style="color:#555;">(<?= e($s['id']) ?>)</span>
                        </span>
                        <span class="entry-tag" style="background-color: #f5f5f5;">family: <?= e((string)$s['entry_type']) ?></span>
                        <span class="entry-tag" style="background-color: #f5f5f5;">
                            throttle: <?= $s['min_interval'] > 0 ? e((string)round($s['min_interval'] / 60)) . ' min' : 'none' ?>
                        </span>
                        <span class="entry-tag" style="background-color: #fff; border-color: #000;">last: <?= e($lastStatus) ?></span>
                        <?php if ($s['is_throttled']): ?>
                            <span class="entry-tag" style="background-color: #fff3cd;">throttled</span>
                        <?php endif; ?>
                    </div>
                    <div class="entry-content" style="margin-top: 8px; font-family: monospace; font-size: 0.9em;">
                        <?php if ($s['last'] === null): ?>
                            Never run.
                        <?php else: ?>
                            last_run: <?= e((string)$lastWhen) ?>
                            · items: <?= (int)$s['last']['item_count'] ?>
                            · duration: <?= (int)$s['last']['duration_ms'] ?> ms
                            <?php if ($nextWhen !== null): ?>
                                · next allowed: <?= e($nextWhen) ?>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (!empty($s['last']['error_message'])): ?>
                            <div style="margin-top: 6px; color: #900;">error: <?= e((string)$s['last']['error_message']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="entry-actions" style="margin-top: 10px;">
                        <form method="post" action="<?= e($basePath) ?>/index.php?action=refresh_plugin" style="display:inline;">
                            <?= CsrfToken::field() ?>
                            <input type="hidden" name="plugin_id" value="<?= e($s['id']) ?>">
                            <button type="submit" class="btn btn-secondary"<?= $satellite ? ' disabled' : '' ?>>Refresh now</button>
                        </form>
                    </div>
                    <?php
                    $hist = $runHistory[$id] ?? [];
                    require __DIR__ . '/partials/plugin_recent_runs.php';
                    ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="latest-entries-section">
            <h2 class="section-title">Plugins (<?= count($status) ?>)</h2>

            <?php if ($status === []): ?>
                <div class="empty-state"><p>No plugins registered.</p></div>
            <?php else: ?>
                <?php foreach ($status as $id => $s): ?>
                    <?php
                        $bg = $statusBg($s['last']);
                        $lastStatus = $s['last']['status'] ?? 'never run';
                        $lastWhen   = $s['last'] !== null ? seismo_format_utc($s['last']['run_at']) : null;
                        $nextWhen   = $s['next_allowed'] !== null ? seismo_format_utc($s['next_allowed']) : null;
                    ?>
                    <div class="entry-card" style="background-color: <?= e($bg) ?>;">
                        <div class="entry-header">
                            <span class="entry-tag" style="background-color: #fff;">
                                <strong><?= e($s['label']) ?></strong>
                                <span style="color:#555;">(<?= e($s['id']) ?>)</span>
                            </span>
                            <span class="entry-tag" style="background-color: #f5f5f5;">
                                family: <?= e($s['entry_type']) ?>
                            </span>
                            <span class="entry-tag" style="background-color: #f5f5f5;">
                                throttle: <?= $s['min_interval'] > 0 ? e((string)round($s['min_interval'] / 60)) . ' min' : 'none' ?>
                            </span>
                            <span class="entry-tag" style="background-color: #fff; border-color: #000;">
                                last: <?= e($lastStatus) ?>
                            </span>
                            <?php if ($s['is_throttled']): ?>
                                <span class="entry-tag" style="background-color: #fff3cd;">throttled</span>
                            <?php endif; ?>
                        </div>

                        <div class="entry-content" style="margin-top: 8px; font-family: monospace; font-size: 0.9em;">
                            <?php if ($s['last'] === null): ?>
                                Never run.
                            <?php else: ?>
                                last_run: <?= e((string)$lastWhen) ?>
                                · items: <?= (int)$s['last']['item_count'] ?>
                                · duration: <?= (int)$s['last']['duration_ms'] ?> ms
                                <?php if ($nextWhen !== null): ?>
                                    · next allowed: <?= e($nextWhen) ?>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if (!empty($s['last']['error_message'])): ?>
                                <div style="margin-top: 6px; color: #900;">
                                    error: <?= e((string)$s['last']['error_message']) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="entry-actions" style="margin-top: 10px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <form method="post" action="<?= e($basePath) ?>/index.php?action=refresh_plugin" style="display:inline;">
                                    <?= CsrfToken::field() ?>
                                    <input type="hidden" name="plugin_id" value="<?= e($s['id']) ?>">
                                    <button type="submit" class="btn btn-secondary"<?= $satellite ? ' disabled' : '' ?>>Refresh now</button>
                                </form>
                                <form method="post" action="<?= e($basePath) ?>/index.php?action=plugin_test" style="display:inline;">
                                    <?= CsrfToken::field() ?>
                                    <input type="hidden" name="plugin_id" value="<?= e($s['id']) ?>">
                                    <button type="submit" class="btn btn-secondary"<?= $satellite ? ' disabled' : '' ?>>Test fetch (no save)</button>
                                </form>
                            </div>
                        </div>
                        <?php
                        $hist = $runHistory[$id] ?? [];
                        require __DIR__ . '/partials/plugin_recent_runs.php';
                        ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($testResult !== null): ?>
            <div class="latest-entries-section" style="margin-top: 24px;">
                <h2 class="section-title">Test fetch result: <?= e($testResult['id']) ?></h2>
                <?php if ($testResult['error'] !== null): ?>
                    <div class="message message-error"><?= e((string)$testResult['error']) ?></div>
                <?php else: ?>
                    <p style="color:#555;">
                        Plugin returned <strong><?= (int)$testResult['count'] ?></strong> row(s).
                        Showing first <?= count($testResult['items']) ?> (no DB writes occurred).
                    </p>
                    <pre style="background:#f5f5f5; padding: 12px; overflow-x: auto; font-size: 0.85em;"><?= e(json_encode($testResult['items'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
