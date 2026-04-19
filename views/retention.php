<?php
/**
 * Retention settings — one row per family, editable inline.
 *
 * @var array<string, array{days: ?int, keep: list<string>, would_delete: ?int}> $rows
 * @var list<string>                                                              $families
 * @var array<string, array{days: ?int, keep: list<string>}>                      $defaults
 * @var string                                                                    $csrfField
 * @var string                                                                    $basePath
 * @var bool                                                                      $satellite
 * @var ?string                                                                   $pageError
 */

declare(strict_types=1);

use Seismo\Http\AuthGate;
use Seismo\Http\CsrfToken;
use Seismo\Service\RetentionService;

if (!function_exists('e')) {
    require_once __DIR__ . '/helpers.php';
}

$accent = seismoBrandAccent();

$familyLabel = [
    'feed_items'      => 'Feed items (RSS, Substack, scraper)',
    'emails'          => 'Emails',
    'lex_items'       => 'Legal text (Lex)',
    'calendar_events' => 'Leg (parliamentary business)',
];

$keepLabel = [
    RetentionService::KEEP_FAVOURITED => 'Keep favourited',
    RetentionService::KEEP_HIGH_SCORE => 'Keep investigation_lead / important',
    RetentionService::KEEP_LABELLED   => 'Keep manually labelled',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Retention &mdash; <?= e(seismoBrandTitle()) ?></title>
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css">
    <?php if ($accent): ?>
    <style>:root { --seismo-accent: <?= e($accent) ?>; }</style>
    <?php endif; ?>
    <style>
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
        <div class="top-bar">
            <div class="top-bar-left">
                <span class="top-bar-title">
                    <a href="<?= e($basePath) ?>/index.php?action=index">
                        <svg class="logo-icon logo-icon-large" viewBox="0 0 24 16" xmlns="http://www.w3.org/2000/svg">
                            <rect width="24" height="16" fill="#FFFFC5"/>
                            <path d="M0,8 L4,12 L6,4 L10,10 L14,2 L18,8 L20,6 L24,8" stroke="#000000" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                    Retention
                </span>
                <span class="top-bar-subtitle">Data retention &amp; prune policy</span>
            </div>
            <div class="top-bar-actions">
                <a href="<?= e($basePath) ?>/index.php?action=diagnostics" class="top-bar-btn" title="Diagnostics">Diagnostics</a>
                <a href="<?= e($basePath) ?>/index.php?action=index" class="top-bar-btn" title="Back to timeline">&larr;</a>
                <?php if (AuthGate::isEnabled() && AuthGate::isLoggedIn()): ?>
                    <form method="post" action="<?= e($basePath) ?>/index.php?action=logout" style="display:inline; margin:0;">
                        <?= CsrfToken::field() ?>
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
        <?php if ($pageError !== null): ?>
            <div class="message message-error"><?= e($pageError) ?></div>
        <?php endif; ?>
        <?php if ($satellite): ?>
            <p class="message message-error">Satellite mode &mdash; entry tables live on the mothership. Policies editable here are preserved for reference but pruning is a mothership-only action.</p>
        <?php endif; ?>

        <div class="latest-entries-section" style="margin-bottom: 24px;">
            <h2 class="section-title">Policy</h2>
            <p style="margin: 0 0 12px; color: #555;">
                "Days" is the age (by insertion timestamp) after which rows may be deleted. Leave empty or set to 0 for <em>unlimited retention</em> &mdash; the family is skipped entirely. Protected rows are never deleted regardless of age.
            </p>

            <form method="post" action="<?= e($basePath) ?>/index.php?action=retention_save">
                <?= $csrfField ?>
                <table class="retention-table">
                    <thead>
                    <tr>
                        <th>Family</th>
                        <th>Retention (days)</th>
                        <th>Protected rows</th>
                        <th>Would delete today</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($families as $family): ?>
                        <?php
                        $row        = $rows[$family] ?? ['days' => null, 'keep' => $defaults[$family]['keep'] ?? [], 'would_delete' => null];
                        $defaultDays = $defaults[$family]['days'] ?? null;
                        $currentKeep = $row['keep'];
                        ?>
                        <tr>
                            <td>
                                <strong><?= e($familyLabel[$family] ?? $family) ?></strong>
                                <div style="font-size: 0.85em; color: #666; margin-top: 2px;">
                                    default: <?= $defaultDays === null ? 'unlimited' : e((string)$defaultDays) . ' days' ?>
                                </div>
                            </td>
                            <td>
                                <input type="number" min="0" max="3650" step="1"
                                       name="<?= e($family) ?>_days"
                                       value="<?= $row['days'] === null ? '' : e((string)$row['days']) ?>"
                                       placeholder="unlimited">
                            </td>
                            <td class="retention-keeps">
                                <?php foreach ($keepLabel as $token => $label): ?>
                                    <label>
                                        <input type="checkbox"
                                               name="<?= e($family) ?>_keep[]"
                                               value="<?= e($token) ?>"
                                               <?= in_array($token, $currentKeep, true) ? 'checked' : '' ?>>
                                        <?= e($label) ?>
                                    </label>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <?php if ($row['would_delete'] === null): ?>
                                    <span class="retention-unlimited">&mdash;</span>
                                <?php else: ?>
                                    <strong><?= e((string)$row['would_delete']) ?></strong>
                                    <?php if ($row['would_delete'] === 0): ?>
                                        <div style="font-size: 0.85em; color: #666;">nothing to prune</div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-primary">Save policies</button>
                    <button type="submit" class="btn" formaction="<?= e($basePath) ?>/index.php?action=retention_preview">Refresh preview</button>
                </div>
            </form>
        </div>

        <div class="latest-entries-section" style="margin-bottom: 24px;">
            <h2 class="section-title">Run prune now</h2>
            <p style="margin: 0 0 12px; color: #555;">
                Runs retention across every family with a configured cutoff, right now. The CLI master cron does the same at the tail of <code>refresh_cron.php</code> (see <code>core-plugin-architecture.mdc</code>). A dry-run is shown above &mdash; the real delete count usually matches, give or take rows inserted between preview and click.
            </p>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=retention_prune">
                <?= $csrfField ?>
                <button type="submit" class="btn btn-primary"<?= $satellite ? ' disabled' : '' ?>
                        onclick="return confirm('Run retention now? Rows matching the policy will be deleted.');">
                    Run retention now
                </button>
            </form>
        </div>
    </div>
</body>
</html>
