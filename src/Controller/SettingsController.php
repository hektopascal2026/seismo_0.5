<?php
/**
 * Unified settings surface (Slice 6): general UI prefs + retention tab.
 */

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Http\CsrfToken;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Service\RetentionService;

final class SettingsController
{
    /** Persisted default timeline page size when `?limit=` is absent. */
    public const KEY_DASHBOARD_LIMIT = 'ui:dashboard_limit';

    public function show(): void
    {
        $tab = (string)($_GET['tab'] ?? 'general');
        if (!in_array($tab, ['general', 'retention'], true)) {
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

        if ($tab === 'retention') {
            try {
                $service = RetentionService::boot($pdo);
                $rows    = $service->previewAll();
            } catch (\Throwable $e) {
                error_log('Seismo settings retention: ' . $e->getMessage());
                $pageError = 'Could not load retention state. Check error_log for details.';
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
}
