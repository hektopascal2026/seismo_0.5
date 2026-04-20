<?php
/**
 * Settings → Magnitu tab.
 *
 * Five stacked sections matching the 0.4 layout:
 *   1. API key (read-only, click-to-copy) + Regenerate button.
 *   2. Seismo API URL (read-only, click-to-copy) — what an admin pastes
 *      into Magnitu's `magnitu_config.json`.
 *   3. Score counts (3 tiles) + last-sync / recipe-version line +
 *      optional "Connected Model" block.
 *   4. Scoring Settings form — alert threshold + sort-by-relevance.
 *      Both keys are written here but NOT yet consumed by 0.5's dashboard /
 *      calendar; the inline note makes that explicit so saving them isn't
 *      misread as "the dashboard will now reorder".
 *   5. Danger Zone — Clear All Scores (DELETE entry_scores + reset recipe).
 *
 * @var string $csrfField
 * @var string $basePath
 * @var array<string, string|null> $magnituConfig  api_key, alert_threshold,
 *      sort_by_relevance, recipe_version, last_sync_at, model_name,
 *      model_version, model_description, model_trained_at
 * @var array{total:int, magnitu:int, recipe:int} $magnituScoreStats
 * @var string $seismoApiUrl
 */

declare(strict_types=1);
?>
        <div class="latest-entries-section">
            <h2 class="section-title">Magnitu</h2>
            <p style="margin: 0 0 16px; color: #555;">
                ML-powered relevance scoring. Connect to your Magnitu instance, manage the API key, and clear the local score table when you want to start fresh.
            </p>

            <!-- Connection info -->
            <div style="margin-bottom: 24px; padding: 16px; border: 2px solid #000;">
                <div style="margin-bottom: 16px;">
                    <label for="magnituApiKey" style="font-size: 12px; font-weight: 600; display: block; margin-bottom: 4px;">API key</label>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <input type="text" id="magnituApiKey"
                               value="<?= e((string)($magnituConfig['api_key'] ?? '')) ?>"
                               readonly
                               style="flex: 1; padding: 6px 10px; border: 2px solid #000; font-family: monospace; font-size: 12px; background: #f5f5f5; cursor: pointer;"
                               onclick="this.select(); document.execCommand('copy'); this.style.borderColor='#00aa00'; setTimeout(()=>this.style.borderColor='#000', 1500);"
                               title="Click to copy">
                        <form method="post" action="<?= e($basePath) ?>/index.php?action=settings_regenerate_magnitu_key" style="margin: 0;">
                            <?= $csrfField ?>
                            <button type="submit" class="btn" onclick="return confirm('Regenerate the API key? Magnitu will need the new key before it can sync again.');">Regenerate</button>
                        </form>
                    </div>
                    <div style="font-size: 12px; margin-top: 4px; color: #555;">Click the key to copy. Paste it into Magnitu's <code>magnitu_config.json</code> as <code>api_key</code>.</div>
                </div>

                <div style="margin-bottom: 4px;">
                    <label for="seismoApiUrl" style="font-size: 12px; font-weight: 600; display: block; margin-bottom: 4px;">Seismo API URL (for Magnitu)</label>
                    <input type="text" id="seismoApiUrl"
                           value="<?= e($seismoApiUrl) ?>"
                           readonly
                           style="width: 100%; padding: 6px 10px; border: 2px solid #000; font-family: monospace; font-size: 12px; background: #f5f5f5; box-sizing: border-box; cursor: pointer;"
                           onclick="this.select(); document.execCommand('copy'); this.style.borderColor='#00aa00'; setTimeout(()=>this.style.borderColor='#000', 1500);"
                           title="Click to copy">
                    <div style="font-size: 12px; margin-top: 4px; color: #555;">Paste into Magnitu's <code>magnitu_config.json</code> as <code>seismo_url</code>.</div>
                </div>
            </div>

            <!-- Score counts -->
            <div style="margin-bottom: 24px; padding: 16px; border: 2px solid #000;">
                <h3 style="margin: 0 0 12px;">Scoring state</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                    <div style="text-align: center; padding: 10px; border: 2px solid #000;">
                        <div style="font-size: 18px; font-weight: 700;"><?= (int)$magnituScoreStats['total'] ?></div>
                        <div style="font-size: 12px;">Entries scored</div>
                    </div>
                    <div style="text-align: center; padding: 10px; border: 2px solid #000;">
                        <div style="font-size: 18px; font-weight: 700;"><?= (int)$magnituScoreStats['magnitu'] ?></div>
                        <div style="font-size: 12px;">By Magnitu (full model)</div>
                    </div>
                    <div style="text-align: center; padding: 10px; border: 2px solid #000;">
                        <div style="font-size: 18px; font-weight: 700;"><?= (int)$magnituScoreStats['recipe'] ?></div>
                        <div style="font-size: 12px;">By Recipe (keywords)</div>
                    </div>
                </div>

                <?php if (!empty($magnituConfig['last_sync_at'])): ?>
                    <?php
                    // Stored value is UTC (`gmdate` in MagnituController); show in SEISMO_VIEW_TIMEZONE
                    // so it matches wall-clock time (raw string looked "2h behind" in Zurich during CEST).
                    $lastSyncRaw = trim((string)$magnituConfig['last_sync_at']);
                    $lastSyncShown = $lastSyncRaw;
                    try {
                        $dtUtc = new \DateTimeImmutable($lastSyncRaw, new \DateTimeZone('UTC'));
                        $lastSyncShown = seismo_format_utc($dtUtc, 'd.m.Y H:i T') ?? $lastSyncRaw;
                    } catch (\Throwable) {
                        // malformed legacy value — show raw
                    }
                    ?>
                    <div style="font-size: 12px; margin-top: 12px;">
                        Last sync: <strong><?= e($lastSyncShown) ?></strong>
                        &middot; Recipe version: <strong><?= e((string)($magnituConfig['recipe_version'] ?? '0')) ?></strong>
                    </div>
                <?php else: ?>
                    <div style="font-size: 12px; margin-top: 12px; color: #555;">
                        No sync yet. Connect Magnitu using the API key and URL above.
                    </div>
                <?php endif; ?>

                <?php if (!empty($magnituConfig['model_name'])): ?>
                <div style="margin-top: 16px; padding: 12px 14px; border: 2px solid #000; background: #fff;">
                    <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 8px;">Connected model</div>
                    <div style="display: flex; gap: 16px; align-items: baseline; flex-wrap: wrap;">
                        <span style="font-size: 18px; font-weight: 700;"><?= e((string)$magnituConfig['model_name']) ?></span>
                        <?php if (!empty($magnituConfig['model_version'])): ?>
                            <span style="font-size: 12px; font-weight: 600; padding: 2px 8px; border: 2px solid #000; background: #FF6B6B;">v<?= e((string)$magnituConfig['model_version']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($magnituConfig['model_description'])): ?>
                        <div style="font-size: 12px; margin-top: 4px;"><?= e((string)$magnituConfig['model_description']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($magnituConfig['model_trained_at'])): ?>
                        <div style="font-size: 12px; margin-top: 6px;">
                            Last trained: <strong><?= e(substr((string)$magnituConfig['model_trained_at'], 0, 16)) ?></strong>
                        </div>
                    <?php endif; ?>
                    <div style="font-size: 11px; margin-top: 8px; color: #555; font-style: italic;">Model files are managed in the Magnitu app.</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Scoring settings -->
            <form method="post" action="<?= e($basePath) ?>/index.php?action=settings_save_magnitu">
                <?= $csrfField ?>
                <div style="margin-bottom: 24px; padding: 16px; border: 2px solid #000;">
                    <h3 style="margin: 0 0 4px;">Scoring preferences</h3>
                    <p style="font-size: 12px; margin: 0 0 12px; color: #555;">
                        These preferences are stored now so Magnitu sync can read them, but the 0.5 timeline and calendar don't yet react to them — wiring lands in a later slice.
                    </p>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                        <div>
                            <label for="alert_threshold" style="font-size: 12px; font-weight: 600; display: block; margin-bottom: 4px;">Alert threshold (0.0 – 1.0)</label>
                            <input type="number" id="alert_threshold" name="alert_threshold"
                                   value="<?= e((string)($magnituConfig['alert_threshold'] ?? '0.75')) ?>"
                                   min="0" max="1" step="0.05"
                                   style="width: 100%; padding: 6px 10px; border: 2px solid #000; font-family: inherit; font-size: 14px; box-sizing: border-box;">
                            <div style="font-size: 12px; margin-top: 4px; color: #555;">Entries scoring above this will be flagged as alerts once the dashboard reads this value.</div>
                        </div>
                        <div>
                            <label style="font-size: 12px; font-weight: 600; display: block; margin-bottom: 4px;">Default sort</label>
                            <label style="display: flex; align-items: center; gap: 8px; padding: 8px 0; cursor: pointer;">
                                <input type="checkbox" name="sort_by_relevance" value="1"
                                       <?= ((string)($magnituConfig['sort_by_relevance'] ?? '0')) === '1' ? 'checked' : '' ?>>
                                <span style="font-size: 14px;">Sort timeline by relevance instead of chronologically</span>
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">Save preferences</button>
                </div>
            </form>

            <!-- Danger zone -->
            <div style="padding: 16px; border: 2px solid #FF2C2C; background: #fff5f5;">
                <h3 style="margin: 0 0 8px; color: #FF2C2C;">Danger zone</h3>
                <p style="font-size: 12px; margin: 0 0 12px;">
                    Delete every row in <code>entry_scores</code> and reset the scoring recipe. The timeline goes back to chronological order. Magnitu's local labels (in the Magnitu app) are untouched and can be re-pushed.
                </p>
                <form method="post" action="<?= e($basePath) ?>/index.php?action=settings_clear_magnitu_scores">
                    <?= $csrfField ?>
                    <button type="submit" class="btn btn-danger" onclick="return confirm('Clear all Magnitu scores and recipe? This cannot be undone.');">
                        Clear all scores
                    </button>
                </form>
            </div>
        </div>
