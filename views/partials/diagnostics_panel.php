<?php
/**
 * Settings → Diagnostics tab — plugin / core fetcher status (embedded panel).
 *
 * @var string $csrfField
 * @var string $basePath
 * @var array<string, array<string, mixed>> $diagStatus
 * @var array<string, array<string, mixed>> $diagCoreStatus
 * @var ?string $diagLoadError
 * @var ?array{id: string, count: int, error: ?string, items: list<array<string, mixed>>} $diagTestResult
 * @var array<string, list<array{run_at: \DateTimeImmutable, status: string, item_count: int, error_message: ?string, duration_ms: int}>> $diagRunHistory
 * @var bool $satellite
 */

declare(strict_types=1);

if (!function_exists('seismo_format_utc')) {
    require_once __DIR__ . '/../helpers.php';
}

$diagCardClass = static function (?array $row): string {
    if ($row === null) {
        return 'entry-card--diag-never';
    }

    return match ($row['status']) {
        'ok'      => 'entry-card--diag-ok',
        'error'   => 'entry-card--diag-error',
        'skipped' => 'entry-card--diag-skipped',
        default   => 'entry-card--diag-warn',
    };
};
?>
        <?php if ($diagLoadError !== null): ?>
            <div class="message message-error"><?= e($diagLoadError) ?></div>
        <?php endif; ?>
        <?php if ($satellite): ?>
            <p class="message message-info">Satellite mode: plugins do not run on this instance. The mothership refreshes the entry tables.</p>
        <?php endif; ?>

        <div class="latest-entries-section module-section-spaced">
            <h2 class="section-title">Master refresh</h2>
            <p class="admin-intro">
                Runs every registered plugin now, ignoring throttle. The CLI cron
                <code>refresh_cron.php</code> calls the same code with throttle on.
            </p>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=refresh_all" class="admin-inline-form">
                <?= $csrfField ?>
                <button type="submit" class="btn btn-primary"<?= $satellite ? ' disabled' : '' ?>>Refresh all now</button>
            </form>
        </div>

        <?php if ($diagCoreStatus !== []): ?>
        <div class="latest-entries-section module-section-spaced">
            <h2 class="section-title">Core fetchers (<?= count($diagCoreStatus) ?>)</h2>
            <p class="admin-intro">RSS (incl. Substack), Parliament press (<code>core:parl_press</code>), scraper, and mail runs share <code>plugin_run_log</code> under synthetic ids (<code>core:*</code>). They run automatically with “Refresh all now” and CLI cron.</p>
            <?php foreach ($diagCoreStatus as $id => $s): ?>
                <?php
                    $cardClass = $diagCardClass($s['last']);
                    $lastStatus = $s['last']['status'] ?? 'never run';
                    $lastWhen   = $s['last'] !== null ? seismo_format_utc($s['last']['run_at']) : null;
                    $nextWhen   = $s['next_allowed'] !== null ? seismo_format_utc($s['next_allowed']) : null;
                ?>
                <div class="entry-card <?= e($cardClass) ?>">
                    <div class="entry-header">
                        <span class="entry-tag entry-tag--surface">
                            <strong><?= e($s['label']) ?></strong>
                            <span class="entry-muted">(<?= e($s['id']) ?>)</span>
                        </span>
                        <span class="entry-tag entry-tag--meta">family: <?= e((string)$s['entry_type']) ?></span>
                        <span class="entry-tag entry-tag--meta">
                            throttle: <?= $s['min_interval'] > 0 ? e((string)round($s['min_interval'] / 60)) . ' min' : 'none' ?>
                        </span>
                        <span class="entry-tag entry-tag--surface entry-tag--emphasis">last: <?= e($lastStatus) ?></span>
                        <?php if ($s['is_throttled']): ?>
                            <span class="entry-tag entry-tag--warn-pill">throttled</span>
                        <?php endif; ?>
                    </div>
                    <div class="entry-content entry-content--mono-sm">
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
                            <div class="diag-inline-error">error: <?= e((string)$s['last']['error_message']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="entry-actions diag-actions">
                        <form method="post" action="<?= e($basePath) ?>/index.php?action=refresh_plugin" class="admin-inline-form">
                            <?= $csrfField ?>
                            <input type="hidden" name="plugin_id" value="<?= e($s['id']) ?>">
                            <button type="submit" class="btn btn-secondary"<?= $satellite ? ' disabled' : '' ?>>Refresh now</button>
                        </form>
                    </div>
                    <?php
                    $hist = $diagRunHistory[$id] ?? [];
                    require __DIR__ . '/plugin_recent_runs.php';
                    ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="latest-entries-section">
            <h2 class="section-title">Plugins (<?= count($diagStatus) ?>)</h2>

            <?php if ($diagStatus === []): ?>
                <div class="empty-state"><p>No plugins registered.</p></div>
            <?php else: ?>
                <?php foreach ($diagStatus as $id => $s): ?>
                    <?php
                        $cardClass = $diagCardClass($s['last']);
                        $lastStatus = $s['last']['status'] ?? 'never run';
                        $lastWhen   = $s['last'] !== null ? seismo_format_utc($s['last']['run_at']) : null;
                        $nextWhen   = $s['next_allowed'] !== null ? seismo_format_utc($s['next_allowed']) : null;
                    ?>
                    <div class="entry-card <?= e($cardClass) ?>">
                        <div class="entry-header">
                            <span class="entry-tag entry-tag--surface">
                                <strong><?= e($s['label']) ?></strong>
                                <span class="entry-muted">(<?= e($s['id']) ?>)</span>
                            </span>
                            <span class="entry-tag entry-tag--meta">
                                family: <?= e($s['entry_type']) ?>
                            </span>
                            <span class="entry-tag entry-tag--meta">
                                throttle: <?= $s['min_interval'] > 0 ? e((string)round($s['min_interval'] / 60)) . ' min' : 'none' ?>
                            </span>
                            <span class="entry-tag entry-tag--surface entry-tag--emphasis">
                                last: <?= e($lastStatus) ?>
                            </span>
                            <?php if ($s['is_throttled']): ?>
                                <span class="entry-tag entry-tag--warn-pill">throttled</span>
                            <?php endif; ?>
                        </div>

                        <div class="entry-content entry-content--mono-sm">
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
                                <div class="diag-inline-error">
                                    error: <?= e((string)$s['last']['error_message']) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="entry-actions diag-actions">
                            <div class="admin-table-actions">
                                <form method="post" action="<?= e($basePath) ?>/index.php?action=refresh_plugin" class="admin-inline-form">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="plugin_id" value="<?= e($s['id']) ?>">
                                    <button type="submit" class="btn btn-secondary"<?= $satellite ? ' disabled' : '' ?>>Refresh now</button>
                                </form>
                                <form method="post" action="<?= e($basePath) ?>/index.php?action=plugin_test" class="admin-inline-form">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="plugin_id" value="<?= e($s['id']) ?>">
                                    <button type="submit" class="btn btn-secondary"<?= $satellite ? ' disabled' : '' ?>>Test fetch (no save)</button>
                                </form>
                            </div>
                        </div>
                        <?php
                        $hist = $diagRunHistory[$id] ?? [];
                        require __DIR__ . '/plugin_recent_runs.php';
                        ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($diagTestResult !== null): ?>
            <div class="latest-entries-section diag-test-section">
                <h2 class="section-title">Test fetch result: <?= e($diagTestResult['id']) ?></h2>
                <?php if ($diagTestResult['error'] !== null): ?>
                    <div class="message message-error"><?= e((string)$diagTestResult['error']) ?></div>
                <?php else: ?>
                    <p class="admin-intro">
                        Plugin returned <strong><?= (int)$diagTestResult['count'] ?></strong> row(s).
                        Showing first <?= count($diagTestResult['items']) ?> (no DB writes occurred).
                    </p>
                    <pre class="pre-json-block"><?= e(json_encode($diagTestResult['items'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                <?php endif; ?>
            </div>
        <?php endif; ?>
