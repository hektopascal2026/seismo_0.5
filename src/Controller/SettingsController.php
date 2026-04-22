<?php
/**
 * Unified settings surface (Slice 6): general UI prefs + retention tab.
 */

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Http\CsrfToken;
use Seismo\Repository\EntryScoreRepository;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Service\RetentionService;

final class SettingsController
{
    /** Persisted default timeline page size when `?limit=` is absent. */
    public const KEY_DASHBOARD_LIMIT = 'ui:dashboard_limit';

    /**
     * `system_config` keys rendered on the Magnitu tab. Kept here (not in the
     * partial) so the controller stays the single place that decides which
     * columns the view needs — the view is a dumb renderer.
     */
    private const MAGNITU_CONFIG_KEYS = [
        'api_key',
        'alert_threshold',
        'sort_by_relevance',
        'recipe_version',
        'last_sync_at',
        'model_name',
        'model_version',
        'model_description',
        'model_trained_at',
    ];

    /**
     * Keys persisted from Settings → Mail (mothership only). Matches
     * {@see \Seismo\Service\CoreRunner::loadMailImapConfig()} / ImapMailFetchService.
     *
     * @var list<string>
     */
    private const MAIL_CONFIG_KEYS = [
        'mail_imap_mailbox',
        'mail_imap_username',
        'mail_imap_password',
        'mail_imap_host',
        'mail_imap_port',
        'mail_imap_flags',
        'mail_imap_folder',
        'mail_max_messages',
        'mail_search_criteria',
        'mail_mark_seen',
    ];

    public function show(): void
    {
        $tab = (string)($_GET['tab'] ?? 'general');
        // 0.4 bookmark: ?tab=satellites
        if ($tab === 'satellites') {
            $tab = 'satellite';
        }

        if (isSatellite()) {
            if (!in_array($tab, ['general', 'magnitu'], true)) {
                header('Location: ' . getBasePath() . '/index.php?action=settings&tab=general', true, 303);
                exit;
            }
        } elseif (!in_array($tab, ['general', 'magnitu', 'retention', 'satellite', 'mail'], true)) {
            $tab = 'general';
        }

        $csrfField = CsrfToken::field();
        $basePath  = getBasePath();
        $pdo       = getDbConnection();
        $config    = new SystemConfigRepository($pdo);

        $rawLimit = $config->get(self::KEY_DASHBOARD_LIMIT);
        $dashboardLimitSaved = DashboardController::DEFAULT_LIMIT_FALLBACK;
        $maxLimit = \Seismo\Repository\EntryRepository::MAX_LIMIT;
        if ($rawLimit !== null && $rawLimit !== '' && ctype_digit($rawLimit)) {
            $dashboardLimitSaved = max(1, min($maxLimit, (int)$rawLimit));
        }

        $pageError = null;
        $rows      = [];
        $defaults  = RetentionService::DEFAULT_POLICIES;
        $satellite = isSatellite();

        // Magnitu tab variables — default to empty so the view can always
        // reference them unconditionally when $tab === 'magnitu'.
        $magnituConfig     = array_fill_keys(self::MAGNITU_CONFIG_KEYS, null);
        $magnituScoreStats = ['total' => 0, 'magnitu' => 0, 'recipe' => 0];
        $seismoApiUrl      = '';

        $satellitesRegistry                   = [];
        $satellitesMothershipUrl              = '';
        $satellitesMothershipDb               = '';
        $satellitesRemoteRefreshKeyConfigured = false;
        $satellitesSuggestedRefreshKey        = '';
        $satellitesHighlightSlug              = '';

        $mailConfig           = array_fill_keys(self::MAIL_CONFIG_KEYS, null);
        $mailPasswordOnFile   = false;

        if ($tab === 'mail') {
            try {
                foreach (self::MAIL_CONFIG_KEYS as $key) {
                    $mailConfig[$key] = $config->get($key);
                }
                $pw = $config->get('mail_imap_password');
                $mailPasswordOnFile = $pw !== null && $pw !== '';
            } catch (\Throwable $e) {
                error_log('Seismo settings mail: ' . $e->getMessage());
                $pageError = 'Could not load mail settings. Check error_log for details.';
            }
        }

        if ($tab === 'retention') {
            try {
                $service = RetentionService::boot($pdo);
                $rows    = $service->previewAll();
            } catch (\Throwable $e) {
                error_log('Seismo settings retention: ' . $e->getMessage());
                $pageError = 'Could not load retention state. Check error_log for details.';
            }
        }

        if ($tab === 'magnitu') {
            try {
                foreach (self::MAGNITU_CONFIG_KEYS as $key) {
                    $magnituConfig[$key] = $config->get($key);
                }
                $magnituScoreStats = (new EntryScoreRepository($pdo))->getScoreCounts();
                $seismoApiUrl      = self::deriveSeismoApiUrl($basePath);
            } catch (\Throwable $e) {
                error_log('Seismo settings magnitu: ' . $e->getMessage());
                $pageError = 'Could not load Magnitu state. Check error_log for details.';
            }

            // After regenerate: show the new key once from session (matches write path).
            $flashKey = MagnituAdminController::SESSION_API_KEY_FLASH;
            if (isset($_SESSION[$flashKey]) && is_string($_SESSION[$flashKey]) && $_SESSION[$flashKey] !== '') {
                $magnituConfig['api_key'] = $_SESSION[$flashKey];
                unset($_SESSION[$flashKey]);
            }
        }

        if ($tab === 'satellite') {
            try {
                $satellitesMothershipUrl = self::mothershipBaseUrl();
                $satellitesMothershipDb  = self::currentDatabaseName($pdo);
                $satellitesRemoteRefreshKeyConfigured = defined('SEISMO_REMOTE_REFRESH_KEY')
                    && (string)SEISMO_REMOTE_REFRESH_KEY !== '';
                $satellitesSuggestedRefreshKey = (string)($config->get('satellites_suggested_refresh_key') ?? '');
                $rawReg = $config->get('satellites_registry');
                if ($rawReg !== null && $rawReg !== '') {
                    $decoded = json_decode($rawReg, true);
                    if (is_array($decoded)) {
                        $satellitesRegistry = array_values($decoded);
                    }
                }
                $satellitesHighlightSlug = trim((string)($_GET['highlight'] ?? ''));
            } catch (\Throwable $e) {
                error_log('Seismo settings satellites: ' . $e->getMessage());
                $pageError = 'Could not load satellite registry. Check error_log for details.';
            }
        }

        // Single source of truth: {@see RetentionService::DEFAULT_POLICIES}
        // already enumerates every family that has a retention contract.
        // Driving the view off that constant means adding a 5th family
        // (e.g. `fetched_emails` fallback, attachments) ripples through
        // automatically instead of drifting here.
        $families = array_keys(RetentionService::DEFAULT_POLICIES);

        require_once SEISMO_ROOT . '/views/helpers.php';
        require SEISMO_ROOT . '/views/settings.php';
    }

    public function saveMail(): void
    {
        if (isSatellite()) {
            $_SESSION['error'] = 'Satellite mode — mail fetch is configured on the mothership only.';
            header('Location: ' . getBasePath() . '/index.php?action=settings&tab=general', true, 303);
            exit;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirectMail();
            return;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirectMail();
            return;
        }

        $mailbox  = trim((string)($_POST['mail_imap_mailbox'] ?? ''));
        $username = trim((string)($_POST['mail_imap_username'] ?? ''));
        $host     = trim((string)($_POST['mail_imap_host'] ?? ''));
        $flags    = trim((string)($_POST['mail_imap_flags'] ?? ''));
        $folder   = trim((string)($_POST['mail_imap_folder'] ?? ''));

        $portRaw = trim((string)($_POST['mail_imap_port'] ?? ''));
        $port    = $portRaw === '' ? 0 : (int)$portRaw;
        if ($port < 0 || $port > 65535) {
            $port = 0;
        }

        $maxRaw = trim((string)($_POST['mail_max_messages'] ?? ''));
        $max    = $maxRaw === '' ? 50 : (int)$maxRaw;
        if ($max < 1) {
            $max = 1;
        }
        if ($max > 500) {
            $max = 500;
        }

        $criteria = trim((string)($_POST['mail_search_criteria'] ?? ''));
        if ($criteria === '') {
            $criteria = 'UNSEEN';
        }

        $markSeen = isset($_POST['mail_mark_seen']) ? '1' : '0';

        $newPassword = (string)($_POST['mail_imap_password'] ?? '');

        try {
            $cfg = new SystemConfigRepository(getDbConnection());
            $cfg->set('mail_imap_username', $username);
            $cfg->set('mail_max_messages', (string)$max);
            $cfg->set('mail_search_criteria', $criteria);
            $cfg->set('mail_mark_seen', $markSeen);

            if ($newPassword !== '') {
                $cfg->set('mail_imap_password', $newPassword);
            }

            if ($mailbox !== '') {
                $cfg->set('mail_imap_mailbox', $mailbox);
                $cfg->set('mail_imap_host', '');
                $cfg->set('mail_imap_port', '');
                $cfg->set('mail_imap_flags', '');
                $cfg->set('mail_imap_folder', '');
            } else {
                $cfg->set('mail_imap_mailbox', '');
                $cfg->set('mail_imap_host', $host);
                if ($port > 0) {
                    $cfg->set('mail_imap_port', (string)$port);
                } else {
                    $cfg->set('mail_imap_port', '');
                }
                $cfg->set('mail_imap_flags', $flags);
                $cfg->set('mail_imap_folder', $folder);
            }

            $_SESSION['success'] = 'Mail settings saved.';
        } catch (\Throwable $e) {
            error_log('Seismo settings_save_mail: ' . $e->getMessage());
            $_SESSION['error'] = 'Could not save mail settings.';
        }

        $this->redirectMail();
    }

    public function saveGeneral(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirectGeneral();
            return;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirectGeneral();
            return;
        }

        $raw = $_POST['dashboard_limit'] ?? '30';
        $n   = (int)$raw;
        if ($n <= 0) {
            $n = DashboardController::DEFAULT_LIMIT_FALLBACK;
        }
        $n = max(1, min(\Seismo\Repository\EntryRepository::MAX_LIMIT, $n));

        try {
            $config = new SystemConfigRepository(getDbConnection());
            $config->set(self::KEY_DASHBOARD_LIMIT, (string)$n);
            $_SESSION['success'] = 'Settings saved.';
        } catch (\Throwable $e) {
            error_log('Seismo settings_save: ' . $e->getMessage());
            $_SESSION['error'] = 'Could not save settings.';
        }

        $this->redirectGeneral();
    }

    private function redirectGeneral(): void
    {
        header('Location: ' . getBasePath() . '/index.php?action=settings&tab=general', true, 303);
        exit;
    }

    private function redirectMail(): void
    {
        header('Location: ' . getBasePath() . '/index.php?action=settings&tab=mail', true, 303);
        exit;
    }

    /**
     * Build the full `scheme://host/basepath/index.php` URL an admin pastes
     * into Magnitu's `magnitu_config.json`. Mirrors the 0.4 derivation in
     * `views/settings.php` (the Magnitu tab). Reads only `$_SERVER` keys —
     * no constants, no config rows — so it works identically whether the
     * instance lives at `/` or at `/seismo/` or behind a reverse proxy.
     *
     * HTTPS detection falls through:
     *   1. `HTTPS` SAPI var (Apache mod_ssl, most direct deployments).
     *   2. `HTTP_X_FORWARDED_PROTO = https` (shared hosts with a TLS
     *      terminator in front of PHP — what hektopascal.org uses).
     *   3. Default `http` — matches 0.4's fallback behaviour.
     */
    private static function deriveSeismoApiUrl(string $basePath): string
    {
        $scheme = 'http';
        $httpsFlag = (string)($_SERVER['HTTPS'] ?? '');
        if ($httpsFlag !== '' && strtolower($httpsFlag) !== 'off') {
            $scheme = 'https';
        } elseif (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            $scheme = 'https';
        }

        $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');

        return $scheme . '://' . $host . $basePath . '/index.php';
    }

    /**
     * Public site base for satellite JSON export (no `/index.php` suffix).
     */
    private static function mothershipBaseUrl(): string
    {
        $scheme = 'http';
        $httpsFlag = (string)($_SERVER['HTTPS'] ?? '');
        if ($httpsFlag !== '' && strtolower($httpsFlag) !== 'off') {
            $scheme = 'https';
        } elseif (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            $scheme = 'https';
        }
        $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
        $bp = getBasePath();

        return $scheme . '://' . $host . ($bp === '' ? '' : $bp);
    }

    private static function currentDatabaseName(\PDO $pdo): string
    {
        try {
            return (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        } catch (\Throwable) {
            return '';
        }
    }
}
