<?php
/**
 * Shared top bar + navigation drawer (Slice 6).
 *
 * @var string $basePath
 * @var string $headerTitle
 * @var string|null $headerSubtitle
 * @var string $activeNav index|filter|about|magnitu|label|feeds|scraper|mail|lex|leg|settings|configuration|styleguide
 * @var string $csrfField
 */

declare(strict_types=1);

use Seismo\Http\AuthGate;

if (!function_exists('seismo_ui_nav_leading_throttle_ms')) {
    require_once __DIR__ . '/../helpers.php';
}
$seismoNavLeadThrottleMs = seismo_ui_nav_leading_throttle_ms();

$activeNav = $activeNav ?? 'index';
$filterNavQs = $filterNavQs ?? 'action=filter';
?>
        <div class="top-bar">
            <div class="top-bar-left">
                <button type="button" id="seismo-nav-toggle" class="top-bar-btn nav-menu-toggle" aria-expanded="false" aria-controls="seismo-nav-drawer" title="Menu">☰</button>
                <span class="top-bar-title">
                    <a href="<?= e($basePath) ?>/index.php?action=index">
                        <img src="<?= e($basePath) ?>/assets/img/logo.png" alt="" class="logo-icon logo-icon-large" width="38" height="38" decoding="async">
                    </a>
                    <?php
                    $brandFull = seismoBrandTitle();
                    if (($headerTitle ?? '') === $brandFull) {
                        echo '<strong class="top-bar-brand-name">' . e(seismoBrandBase()) . '</strong>';
                        echo ' <span class="top-bar-brand-version">' . e(seismoBrandVersionLabel()) . '</span>';
                    } else {
                        echo '<strong class="top-bar-page-title">' . e((string)$headerTitle) . '</strong>';
                    }
                    ?>
                </span>
                <?php if (($headerSubtitle ?? '') !== ''): ?>
                <span class="top-bar-subtitle"><?= e((string)$headerSubtitle) ?></span>
                <?php endif; ?>
            </div>
            <div class="top-bar-actions">
                <?php
                    $timelineRefreshAct = $timelineRefreshAction ?? 'refresh_all';
                    $timelineRefreshRet = $timelineRefreshReturnAction ?? 'index';
                    ?>
                <?php if (!empty($showTimelineRefresh) && ($activeNav === 'index' || $activeNav === 'filter')): ?>
                    <form method="post" action="<?= e($basePath) ?>/index.php?action=<?= e($timelineRefreshAct) ?>" class="admin-inline-form top-bar-form-gap">
                        <?= $csrfField ?>
                        <input type="hidden" name="return_action" value="<?= e($timelineRefreshRet) ?>">
                        <button type="submit" class="top-bar-btn top-bar-btn--text" title="<?= isSatellite() ? 'Fetch all sources on the mothership (remote refresh)' : 'Fetch all sources (same as Settings → Diagnostics → Refresh all)' ?>">Refresh</button>
                    </form>
                <?php endif; ?>
                <?php if (AuthGate::isEnabled() && AuthGate::isLoggedIn()): ?>
                    <form method="post" action="<?= e($basePath) ?>/index.php?action=logout" class="admin-inline-form top-bar-form-flush">
                        <?= $csrfField ?>
                        <button type="submit" class="top-bar-btn top-bar-btn--text" title="Sign out">Logout</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <nav id="seismo-nav-drawer" class="nav-drawer" aria-label="Main navigation" aria-hidden="true">
            <a href="<?= e($basePath) ?>/index.php?action=index" class="nav-link<?= $activeNav === 'index' ? ' active' : '' ?>">Timeline</a>
            <?php if (!isSatellite()): ?>
            <a href="<?= e($basePath) ?>/index.php?<?= e($filterNavQs) ?>" class="nav-link<?= $activeNav === 'filter' ? ' active' : '' ?>">Filter</a>
            <?php endif; ?>
            <a href="<?= e($basePath) ?>/index.php?action=magnitu" class="nav-link<?= $activeNav === 'magnitu' ? ' active' : '' ?>">Highlights</a>
            <a href="<?= e($basePath) ?>/index.php?action=label" class="nav-link<?= $activeNav === 'label' ? ' active' : '' ?>">Label</a>
            <?php if (!isSatellite()): ?>
            <a href="<?= e($basePath) ?>/index.php?action=feeds" class="nav-link<?= $activeNav === 'feeds' ? ' active' : '' ?>">Feeds</a>
            <a href="<?= e($basePath) ?>/index.php?action=scraper" class="nav-link<?= $activeNav === 'scraper' ? ' active' : '' ?>">Scraper</a>
            <a href="<?= e($basePath) ?>/index.php?action=mail" class="nav-link<?= $activeNav === 'mail' ? ' active' : '' ?>">Mail</a>
            <a href="<?= e($basePath) ?>/index.php?action=lex" class="nav-link<?= $activeNav === 'lex' ? ' active' : '' ?>">Lex</a>
            <a href="<?= e($basePath) ?>/index.php?action=leg" class="nav-link<?= $activeNav === 'leg' ? ' active' : '' ?>">Leg</a>
            <a href="<?= e($basePath) ?>/index.php?action=styleguide" class="nav-link<?= $activeNav === 'styleguide' ? ' active' : '' ?>">Styleguide</a>
            <?php endif; ?>
            <a href="<?= e($basePath) ?>/index.php?action=settings" class="nav-link<?= $activeNav === 'settings' ? ' active' : '' ?>">Settings</a>
            <?php if (!isSatellite()): ?>
            <a href="<?= e($basePath) ?>/index.php?action=configuration" class="nav-link<?= $activeNav === 'configuration' ? ' active' : '' ?>">Configuration</a>
            <a href="<?= e($basePath) ?>/index.php?action=about" class="nav-link<?= $activeNav === 'about' ? ' active' : '' ?>">About</a>
            <?php endif; ?>
        </nav>
        <script>
        (function() {
            var btn = document.getElementById('seismo-nav-toggle');
            var nav = document.getElementById('seismo-nav-drawer');
            if (!btn || !nav) return;
            btn.addEventListener('click', function() {
                var open = nav.classList.toggle('open');
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                nav.setAttribute('aria-hidden', open ? 'false' : 'true');
            });
        })();
        </script>
        <?php if ($seismoNavLeadThrottleMs > 0): ?>
        <script>
        (function() {
            var lockUntil = 0;
            var ms = <?= (int) $seismoNavLeadThrottleMs ?>;
            document.addEventListener('click', function(e) {
                if (e.defaultPrevented) {
                    return;
                }
                if (e.button !== 0) {
                    return;
                }
                if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
                    return;
                }
                var t = e.target;
                if (!t || !t.closest) {
                    return;
                }
                var a = t.closest('a[href]');
                if (!a) {
                    return;
                }
                if (!a.matches('#seismo-nav-drawer a[href], .settings-tabs a[href]')) {
                    return;
                }
                var now = Date.now();
                if (now < lockUntil) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    return;
                }
                lockUntil = now + ms;
            }, true);
        })();
        </script>
        <?php endif; ?>
