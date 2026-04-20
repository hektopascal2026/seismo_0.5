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
            <p class="admin-intro">
                Default number of entries on the main timeline when you open the dashboard without a <code>?limit=</code> query parameter. You can still override per visit (1–<?= (int)$dashboardLimitMax ?>).
            </p>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=settings_save" class="admin-form-card">
                <?= $csrfField ?>
                <div class="admin-form-field">
                    <label for="dashboard_limit">Entries per page</label>
                    <input type="number" id="dashboard_limit" name="dashboard_limit" min="1" max="<?= (int)$dashboardLimitMax ?>"
                           value="<?= (int)$dashboardLimitSaved ?>"
                           class="search-input" style="width:7rem;">
                </div>
                <div class="admin-form-actions">
                    <button type="submit" class="btn btn-success">Save</button>
                </div>
            </form>
        </div>

        <div class="latest-entries-section module-section-spaced">
            <h2 class="section-title">Display timezone</h2>
            <p class="admin-intro">
                Timestamps are stored in UTC. Day groupings (“Heute”, “Gestern”) and clock times in the UI use the timezone from
                <code>SEISMO_VIEW_TIMEZONE</code> in <code>config.local.php</code> (default <code>Europe/Zurich</code>).
            </p>
        </div>
