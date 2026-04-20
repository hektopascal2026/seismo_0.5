<?php
/**
 * Shared top bar + navigation drawer (Slice 6).
 *
 * @var string $basePath
 * @var string $headerTitle
 * @var string|null $headerSubtitle
 * @var string $activeNav index|about|magnitu|feeds|scraper|mail|lex|leg|diagnostics|settings|styleguide
 * @var string $csrfField
 */

declare(strict_types=1);

use Seismo\Http\AuthGate;

$activeNav = $activeNav ?? 'index';
?>
        <div class="top-bar">
            <div class="top-bar-left">
                <button type="button" id="seismo-nav-toggle" class="top-bar-btn nav-menu-toggle" aria-expanded="false" aria-controls="seismo-nav-drawer" title="Menu">☰</button>
                <span class="top-bar-title">
                    <a href="<?= e($basePath) ?>/index.php?action=index">
                        <svg class="logo-icon logo-icon-large" viewBox="0 0 24 16" xmlns="http://www.w3.org/2000/svg">
                            <rect width="24" height="16" fill="#FFFFC5"/>
                            <path d="M0,8 L4,12 L6,4 L10,10 L14,2 L18,8 L20,6 L24,8" stroke="#000000" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                    <?= e($headerTitle) ?>
                </span>
                <?php if (($headerSubtitle ?? '') !== ''): ?>
                <span class="top-bar-subtitle"><?= e((string)$headerSubtitle) ?></span>
                <?php endif; ?>
            </div>
            <div class="top-bar-actions">
                <?php if (!empty($showTimelineRefresh) && $activeNav === 'index'): ?>
                    <form method="post" action="<?= e($basePath) ?>/index.php?action=refresh_all" class="admin-inline-form" style="margin:0 8px 0 0;">
                        <?= $csrfField ?>
                        <input type="hidden" name="return_action" value="index">
                        <button type="submit" class="top-bar-btn top-bar-btn--text" title="Fetch all sources (same as Diagnostics → Refresh all)">Refresh</button>
                    </form>
                <?php endif; ?>
                <?php if (AuthGate::isEnabled() && AuthGate::isLoggedIn()): ?>
                    <form method="post" action="<?= e($basePath) ?>/index.php?action=logout" class="admin-inline-form" style="margin:0;">
                        <?= $csrfField ?>
                        <button type="submit" class="top-bar-btn top-bar-btn--text" title="Sign out">Logout</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <nav id="seismo-nav-drawer" class="nav-drawer" aria-label="Main navigation" aria-hidden="true">
            <a href="<?= e($basePath) ?>/index.php?action=index" class="nav-link<?= $activeNav === 'index' ? ' active' : '' ?>">Timeline</a>
            <a href="<?= e($basePath) ?>/index.php?action=about" class="nav-link<?= $activeNav === 'about' ? ' active' : '' ?>">About</a>
            <a href="<?= e($basePath) ?>/index.php?action=magnitu" class="nav-link<?= $activeNav === 'magnitu' ? ' active' : '' ?>">Highlights</a>
            <a href="<?= e($basePath) ?>/index.php?action=feeds" class="nav-link<?= $activeNav === 'feeds' ? ' active' : '' ?>">Feeds</a>
            <a href="<?= e($basePath) ?>/index.php?action=scraper" class="nav-link<?= $activeNav === 'scraper' ? ' active' : '' ?>">Scraper</a>
            <a href="<?= e($basePath) ?>/index.php?action=mail" class="nav-link<?= $activeNav === 'mail' ? ' active' : '' ?>">Mail</a>
            <a href="<?= e($basePath) ?>/index.php?action=lex" class="nav-link<?= $activeNav === 'lex' ? ' active' : '' ?>">Lex</a>
            <a href="<?= e($basePath) ?>/index.php?action=leg" class="nav-link<?= $activeNav === 'leg' ? ' active' : '' ?>">Leg</a>
            <a href="<?= e($basePath) ?>/index.php?action=diagnostics" class="nav-link<?= $activeNav === 'diagnostics' ? ' active' : '' ?>">Diagnostics</a>
            <a href="<?= e($basePath) ?>/index.php?action=setup" class="nav-link<?= $activeNav === 'setup' ? ' active' : '' ?>">Setup</a>
            <a href="<?= e($basePath) ?>/index.php?action=settings" class="nav-link<?= $activeNav === 'settings' ? ' active' : '' ?>">Settings</a>
            <a href="<?= e($basePath) ?>/index.php?action=styleguide" class="nav-link<?= $activeNav === 'styleguide' ? ' active' : '' ?>">Styleguide</a>
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
