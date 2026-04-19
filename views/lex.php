<?php
/**
 * Lex legislation list (multi-source read; Fedlex refresh in 0.5 only).
 *
 * @var array<int, array<string, mixed>> $lexItems
 * @var array<string, mixed> $lexCfg
 * @var list<string> $enabledLexSources
 * @var list<string> $activeSources
 * @var ?string $pageError
 * @var array<string, ?\DateTimeImmutable> $lastFetchedBySource Per-source MAX(fetched_at) in UTC (view formats to Zurich).
 * @var string $basePath
 * @var bool $satellite
 * @var array<string, mixed> $chCfg
 * @var string $csrfField Hidden CSRF inputs (LexController)
 */

declare(strict_types=1);

use Seismo\Http\AuthGate;

if (!function_exists('seismo_format_lex_refresh_utc')) {
    require_once __DIR__ . '/helpers.php';
}

$accent = seismoBrandAccent();
$chResourceTypesStr = '';
if (!empty($chCfg['resource_types']) && is_array($chCfg['resource_types'])) {
    $ids = [];
    foreach ($chCfg['resource_types'] as $rt) {
        if (is_array($rt) && isset($rt['id'])) {
            $ids[] = (string)(int)$rt['id'];
        }
    }
    $chResourceTypesStr = implode(', ', $ids);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lex — <?= e(seismoBrandTitle()) ?></title>
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css">
    <?php if ($accent): ?>
    <style>:root { --seismo-accent: <?= e($accent) ?>; }</style>
    <?php endif; ?>
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
                    Lex
                </span>
                <span class="top-bar-subtitle">EU, Swiss &amp; German legislation</span>
            </div>
            <div class="top-bar-actions">
                <a href="<?= e($basePath) ?>/index.php?action=leg" class="top-bar-btn" title="Leg">Leg</a>
                <a href="<?= e($basePath) ?>/index.php?action=diagnostics" class="top-bar-btn" title="Diagnostics">Diag</a>
                <a href="<?= e($basePath) ?>/index.php?action=index" class="top-bar-btn" title="Back to timeline">←</a>
                <?php if (AuthGate::isEnabled() && AuthGate::isLoggedIn()): ?>
                    <form method="post" action="<?= e($basePath) ?>/index.php?action=logout" style="display:inline; margin:0;">
                        <?= $csrfField ?>
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

        <p class="message" style="background:#f5f5f5;border-color:#ccc;">
            <strong>Seismo 0.5:</strong> Only <strong>Swiss Fedlex</strong> (<code>ch</code>) can be refreshed here.
            Other sources stay populated by your Seismo 0.4 instance (or manual DB) until multi-source refresh lands in a later slice.
        </p>

        <?php if ($satellite): ?>
            <p class="message message-error">Satellite mode: legislation rows are read from the mothership. Refresh is disabled.</p>
        <?php endif; ?>

        <form method="get" action="<?= e($basePath) ?>/index.php" id="lex-filter-form">
            <input type="hidden" name="action" value="lex">
            <input type="hidden" name="sources_submitted" value="1">
            <div class="tag-filter-section" style="margin-bottom: 16px;">
                <div class="tag-filter-list">
                    <?php
                    $lexPagePills = [
                        ['key' => 'eu', 'label' => '🇪🇺 EU'],
                        ['key' => 'ch', 'label' => '🇨🇭 Switzerland'],
                        ['key' => 'de', 'label' => '🇩🇪 Germany'],
                        ['key' => 'fr', 'label' => '🇫🇷 France'],
                        ['key' => 'parl_mm', 'label' => '🏛 Parl MM'],
                    ];
                    foreach ($lexPagePills as $pill):
                        if (!in_array($pill['key'], $enabledLexSources, true)) {
                            continue;
                        }
                        $isActive = in_array($pill['key'], $activeSources, true);
                    ?>
                    <label class="tag-filter-pill<?= $isActive ? ' tag-filter-pill-active' : '' ?>"<?= $isActive ? ' style="background-color: #f5f562;"' : '' ?>>
                        <input type="checkbox" name="sources[]" value="<?= e($pill['key']) ?>" <?= $isActive ? 'checked' : '' ?> onchange="this.form.submit()">
                        <span><?= e($pill['label']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </form>

        <?php if (!$satellite): ?>
        <div class="latest-entries-section" style="margin-bottom: 24px;">
            <h2 class="section-title">Refresh Swiss Fedlex</h2>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=refresh_fedlex" style="display:inline;">
                <?= $csrfField ?>
                <button type="submit" class="btn btn-primary">Refresh Fedlex (CH)</button>
            </form>
        </div>

        <div class="latest-entries-section" style="margin-bottom: 24px;">
            <h2 class="section-title">Fedlex settings (CH only)</h2>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=save_lex_ch" style="max-width:520px;">
                <?= $csrfField ?>
                <div style="margin-bottom:12px;">
                    <label><input type="checkbox" name="ch_enabled" value="1" <?= !empty($chCfg['enabled']) ? 'checked' : '' ?>> Enabled</label>
                </div>
                <div style="margin-bottom:12px;">
                    <label>Language (Fedlex expression)<br>
                    <input type="text" name="ch_language" value="<?= e((string)($chCfg['language'] ?? 'DEU')) ?>" maxlength="8" style="width:100%;"></label>
                </div>
                <div style="margin-bottom:12px;">
                    <label>Lookback days<br>
                    <input type="number" name="ch_lookback_days" value="<?= (int)($chCfg['lookback_days'] ?? 90) ?>" min="1" style="width:100%;"></label>
                </div>
                <div style="margin-bottom:12px;">
                    <label>SPARQL row limit<br>
                    <input type="number" name="ch_limit" value="<?= (int)($chCfg['limit'] ?? 100) ?>" min="1" style="width:100%;"></label>
                </div>
                <div style="margin-bottom:12px;">
                    <label>Resource type IDs (comma-separated)<br>
                    <input type="text" name="ch_resource_types" value="<?= e($chResourceTypesStr) ?>" style="width:100%;"></label>
                </div>
                <div style="margin-bottom:12px;">
                    <label>Notes<br>
                    <textarea name="ch_notes" rows="2" style="width:100%;"><?= e((string)($chCfg['notes'] ?? '')) ?></textarea></label>
                </div>
                <button type="submit" class="btn btn-secondary">Save Fedlex settings</button>
            </form>
        </div>
        <?php endif; ?>

        <div class="latest-entries-section">
            <div class="section-title-row">
                <h2 class="section-title">
                    <?php
                    $lexRefreshLineMeta = [
                        ['key' => 'eu', 'emoji' => '🇪🇺'],
                        ['key' => 'ch', 'emoji' => '🇨🇭'],
                        ['key' => 'de', 'emoji' => '🇩🇪'],
                        ['key' => 'fr', 'emoji' => '🇫🇷'],
                        ['key' => 'parl_mm', 'emoji' => '🏛'],
                    ];
                    $refreshParts = [];
                    foreach ($lexRefreshLineMeta as $meta) {
                        $dtUtc = $lastFetchedBySource[$meta['key']] ?? null;
                        $line = seismo_format_lex_refresh_utc($dtUtc);
                        if ($line !== null && $line !== '') {
                            $refreshParts[] = $meta['emoji'] . ' ' . $line;
                        }
                    }
                    if ($refreshParts !== []):
                    ?>
                        Refreshed: <?= implode(' · ', array_map('e', $refreshParts)) ?>
                    <?php else: ?>
                        Refreshed: Never
                    <?php endif; ?>
                </h2>
            </div>

            <?php if ($lexItems === []): ?>
                <div class="empty-state">
                    <p>No legislation in this filter yet. Enable sources via the CH settings form above, or trigger a Fedlex refresh.</p>
                </div>
            <?php else: ?>
                <?php
                    $activeCount = count($activeSources);
                    $showSourceTag = ($activeCount > 1);
                ?>
                <?php foreach ($lexItems as $item): ?>
                    <?php
                        $source = $item['source'] ?? 'eu';
                        if ($source === 'parl_mm') {
                            $sourceEmoji = '🏛';
                            $sourceLabel = 'Parl MM';
                            $linkLabel = 'parlament.ch →';
                        } elseif ($source === 'fr') {
                            $sourceEmoji = '🇫🇷';
                            $sourceLabel = 'FR';
                            $linkLabel = 'Légifrance →';
                        } elseif ($source === 'de') {
                            $sourceEmoji = '🇩🇪';
                            $sourceLabel = 'DE';
                            $linkLabel = 'recht.bund.de →';
                        } elseif ($source === 'ch') {
                            $sourceEmoji = '🇨🇭';
                            $sourceLabel = 'CH';
                            $linkLabel = 'Fedlex →';
                        } else {
                            $sourceEmoji = '🇪🇺';
                            $sourceLabel = 'EU';
                            $linkLabel = 'EUR-Lex →';
                        }
                        $docType = (string)($item['document_type'] ?? 'Legislation');
                        $itemUrl = (string)($item['eurlex_url'] ?? '#');
                    ?>
                    <div class="entry-card">
                        <div class="entry-header">
                            <?php if ($showSourceTag): ?>
                                <span class="entry-tag" style="background-color: #f5f562; border-color: #000000;">
                                    <?= e($sourceEmoji) ?> <?= e($sourceLabel) ?>
                                </span>
                            <?php endif; ?>
                            <span class="entry-tag" style="background-color: #f5f5f5;">
                                <?= e($docType) ?>
                            </span>
                        </div>
                        <h3 class="entry-title">
                            <a href="<?= e($itemUrl) ?>" target="_blank" rel="noopener">
                                <?= e((string)($item['title'] ?? '')) ?>
                            </a>
                        </h3>
                        <?php
                            $lexDesc = trim((string)($item['description'] ?? ''));
                            $lexPreview = mb_substr($lexDesc, 0, 300);
                            if (mb_strlen($lexDesc) > 300) {
                                $lexPreview .= '...';
                            }
                            $lexHasMore = mb_strlen($lexDesc) > 300;
                        ?>
                        <?php if ($lexDesc !== ''): ?>
                            <div class="entry-content entry-preview"><?= nl2br(e($lexPreview)) ?></div>
                            <?php if ($lexHasMore): ?>
                                <div class="entry-full-content" style="display: none;"><?= nl2br(e($lexDesc)) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="entry-actions">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <?php if ($lexHasMore): ?>
                                    <button type="button" class="btn btn-secondary entry-expand-btn">expand &#9660;</button>
                                <?php endif; ?>
                                <?php if ($source !== 'parl_mm'): ?>
                                    <span style="font-family: monospace;"><?= e((string)($item['celex'] ?? '')) ?></span>
                                    <a href="<?= e($itemUrl) ?>" target="_blank" rel="noopener" class="entry-link"><?= e($linkLabel) ?></a>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($item['document_date'])): ?>
                                <span class="entry-date"><?= e(date('d.m.Y', strtotime((string)$item['document_date']))) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
    (function() {
        function collapseEntry(card, btn) {
            var preview = card.querySelector('.entry-preview');
            var full = card.querySelector('.entry-full-content');
            if (!preview || !full) return;
            full.style.display = 'none';
            preview.style.display = '';
            if (btn) btn.innerHTML = 'expand \u25BC';
        }
        function expandEntry(card, btn) {
            var preview = card.querySelector('.entry-preview');
            var full = card.querySelector('.entry-full-content');
            if (!preview || !full) return;
            preview.style.display = 'none';
            full.style.display = 'block';
            if (btn) btn.innerHTML = 'collapse \u25B2';
        }
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.entry-expand-btn');
            if (!btn) return;
            var card = btn.closest('.entry-card');
            if (!card) return;
            var full = card.querySelector('.entry-full-content');
            if (full && full.style.display === 'none') {
                expandEntry(card, btn);
            } else {
                collapseEntry(card, btn);
            }
        });
    })();
    </script>
</body>
</html>
