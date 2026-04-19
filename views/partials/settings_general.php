<?php
/**
 * Settings → General tab: dashboard page size.
 *
 * @var string $csrfField
 * @var string $basePath
 * @var int $dashboardLimitSaved
 * @var int $dashboardLimitMax
 */

declare(strict_types=1);
?>
        <div class="latest-entries-section">
            <h2 class="section-title">Timeline</h2>
            <p style="margin: 0 0 12px; color: #555;">
                Default number of entries on the main timeline when you open the dashboard without a <code>?limit=</code> query parameter. You can still override per visit (1–<?= (int)$dashboardLimitMax ?>).
            </p>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=settings_save">
                <?= $csrfField ?>
                <label for="dashboard_limit">Entries per page</label>
                <input type="number" id="dashboard_limit" name="dashboard_limit" min="1" max="<?= (int)$dashboardLimitMax ?>"
                       value="<?= (int)$dashboardLimitSaved ?>"
                       style="margin-left: 8px; padding: 8px 10px; border: 2px solid #000; width: 6rem;">
                <button type="submit" class="btn btn-primary" style="margin-left: 8px;">Save</button>
            </form>
        </div>

        <div class="latest-entries-section" style="margin-top: 24px;">
            <h2 class="section-title">Display timezone</h2>
            <p style="margin: 0 0 12px; color: #555;">
                Timestamps are stored in UTC. Day groupings (“Heute”, “Gestern”) and clock times in the UI use the timezone from
                <code>SEISMO_VIEW_TIMEZONE</code> in <code>config.local.php</code> (default <code>Europe/Zurich</code>).
            </p>
        </div>
