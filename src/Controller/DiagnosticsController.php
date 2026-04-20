<?php

declare(strict_types=1);

namespace Seismo\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Seismo\Http\CsrfToken;
use Seismo\Repository\PluginRunLogRepository;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Service\CoreRunner;
use Seismo\Service\PluginRegistry;
use Seismo\Service\RefreshAllService;

/**
 * Diagnostics page — plugin-run status surface.
 *
 * Slice 3 scope (intentionally minimal):
 *   - Status table with one row per registered plugin (latest run from
 *     plugin_run_log + throttle window).
 *   - "Refresh all now" master button (force=true; bypasses throttle).
 *   - Per-plugin "Refresh now" button (force=true).
 *   - Per-plugin "Test fetch" button (calls fetch() without persisting,
 *     stashes a peek of the rows in a session flash).
 *
 * Out of scope for Slice 3 (graduates later):
 *   - History strip (recentForPlugin) — code is in place, UI lands when needed.
 *   - Inline config viewer — admin still uses Lex/Leg pages for config.
 *
 * All POST endpoints require CSRF and run behind AuthGate (router enforces it
 * because `diagnostics`, `refresh_all`, `refresh_plugin`, `plugin_test` are
 * NOT on the AuthGate public whitelist).
 */
final class DiagnosticsController
{
    /** Shared with {@see self::refreshAllRemote()} — 60s cooldown between full refreshes (0.4 parity). */
    private const KEY_LAST_REFRESH_AT = 'last_refresh_at';

    public function show(): void
    {
        $registry = new PluginRegistry();
        $plugins  = $registry->all();

        $coreMeta = [
            CoreRunner::ID_RSS         => ['label' => 'RSS & Substack', 'min_interval' => 1800, 'entry_type' => 'feed_items'],
            CoreRunner::ID_PARL_PRESS  => ['label' => 'Parlament Medien (press)', 'min_interval' => 1800, 'entry_type' => 'feed_items'],
            CoreRunner::ID_SCRAPER     => ['label' => 'Scraper pages', 'min_interval' => 3600, 'entry_type' => 'feed_items'],
            CoreRunner::ID_MAIL        => ['label' => 'Mail (IMAP)', 'min_interval' => 900, 'entry_type' => 'emails'],
        ];

        $status     = [];
        $coreStatus = [];
        $loadError  = null;
        $runHistory = [];

        try {
            $pdo        = getDbConnection();
            $log        = new PluginRunLogRepository($pdo);
            $ids        = array_merge(array_keys($coreMeta), array_keys($plugins));
            $latest     = $log->latestPerPlugin($ids);
            $runHistory = $log->recentForPlugins($ids, 8);
        } catch (\Throwable $e) {
            error_log('Seismo diagnostics: ' . $e->getMessage());
            $loadError = 'Could not read plugin_run_log. Has the latest migration run yet? (?action=migrate&key=…)';
            $latest = [];
            $runHistory = [];
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        foreach ($coreMeta as $id => $meta) {
            $row = $latest[$id] ?? null;
            $minInterval = (int)$meta['min_interval'];
            $nextAllowed = null;
            if ($row !== null && $row['status'] === 'ok' && $minInterval > 0) {
                $nextAllowed = $row['run_at']->modify('+' . $minInterval . ' seconds');
            }
            $coreStatus[$id] = [
                'id'           => $id,
                'label'        => $meta['label'],
                'entry_type'   => $meta['entry_type'],
                'config_key'   => '—',
                'min_interval' => $minInterval,
                'last'         => $row,
                'next_allowed' => $nextAllowed,
                'is_throttled' => $nextAllowed !== null && $nextAllowed > $now,
                'is_core'      => true,
            ];
        }

        foreach ($plugins as $id => $plugin) {
            $row = $latest[$id] ?? null;
            $minInterval = $plugin->getMinIntervalSeconds();

            $nextAllowed = null;
            if ($row !== null && $row['status'] === 'ok' && $minInterval > 0) {
                $nextAllowed = $row['run_at']->modify('+' . $minInterval . ' seconds');
            }

            $status[$id] = [
                'id'            => $id,
                'label'         => $plugin->getLabel(),
                'entry_type'    => $plugin->getEntryType(),
                'config_key'    => $plugin->getConfigKey(),
                'min_interval'  => $minInterval,
                'last'          => $row,
                'next_allowed'  => $nextAllowed,
                'is_throttled'  => $nextAllowed !== null && $nextAllowed > $now,
                'is_core'       => false,
            ];
        }

        // Keep `diagnostics` registered as NOT read-only so this unset persists
        // (read-only routes call session_write_close() before the controller runs).
        $testResult = $_SESSION['plugin_test_result'] ?? null;
        unset($_SESSION['plugin_test_result']);

        $basePath  = getBasePath();
        $satellite = isSatellite();
        $csrfField = CsrfToken::field();

        require_once SEISMO_ROOT . '/views/helpers.php';
        require SEISMO_ROOT . '/views/diagnostics.php';
    }

    public function refreshAll(): void
    {
        if (!$this->guardPost()) {
            return;
        }

        $pdo = getDbConnection();
        $config = new SystemConfigRepository($pdo);
        $last = $config->get(self::KEY_LAST_REFRESH_AT);
        if ($last !== null && $last !== '' && ctype_digit($last) && (time() - (int)$last) < 60) {
            $remaining = 60 - (time() - (int)$last);
            $_SESSION['error'] = "Please wait {$remaining}s before refreshing again.";
            $this->redirectAfterRefresh();

            return;
        }
        $config->set(self::KEY_LAST_REFRESH_AT, (string)time());

        try {
            $results = RefreshAllService::boot($pdo)->runAll(true);
        } catch (\Throwable $e) {
            error_log('Seismo diagnostics refresh_all: ' . $e->getMessage());
            $_SESSION['error'] = 'Refresh all failed: ' . $e->getMessage();
            $this->redirectAfterRefresh();

            return;
        }

        $okCount = 0;
        $errCount = 0;
        $itemsTotal = 0;
        foreach ($results as $r) {
            if ($r->isOk()) {
                $okCount++;
                $itemsTotal += $r->count;
            } elseif ($r->status === 'error') {
                $errCount++;
            }
        }
        $_SESSION['success'] = sprintf(
            'Refresh all: %d ok (%d items), %d error, %d skipped.',
            $okCount,
            $itemsTotal,
            $errCount,
            count($results) - $okCount - $errCount
        );

        $this->redirectAfterRefresh();
    }

    /**
     * Satellite-callable full refresh — validates `?key=` against
     * `SEISMO_REMOTE_REFRESH_KEY`. JSON response; no session (safe for cross-origin
     * fetch from a public satellite page). Port of 0.4 `handleRefreshAllRemote`.
     */
    public function refreshAllRemote(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $expected = defined('SEISMO_REMOTE_REFRESH_KEY') ? (string)SEISMO_REMOTE_REFRESH_KEY : '';
        if ($expected === '') {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'remote refresh disabled']);

            return;
        }

        if (isSatellite()) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'this instance is a satellite; call the mothership']);

            return;
        }

        $provided = (string)($_GET['key'] ?? $_POST['key'] ?? '');
        if (!hash_equals($expected, $provided)) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'invalid key']);

            return;
        }

        set_time_limit(300);

        $pdo = getDbConnection();
        $config = new SystemConfigRepository($pdo);
        $last = $config->get(self::KEY_LAST_REFRESH_AT);
        if ($last !== null && $last !== '' && ctype_digit($last) && (time() - (int)$last) < 60) {
            $remaining = 60 - (time() - (int)$last);
            http_response_code(429);
            echo json_encode([
                'ok' => false,
                'error' => "rate limited, retry in {$remaining}s",
                'retry_after' => $remaining,
            ]);

            return;
        }
        $config->set(self::KEY_LAST_REFRESH_AT, (string)time());

        $startedAt = microtime(true);
        try {
            $results = RefreshAllService::boot($pdo)->runAll(true);
        } catch (\Throwable $e) {
            error_log('Seismo refresh_all_remote: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);

            return;
        }

        $hasErrors = false;
        $messages = [];
        foreach ($results as $id => $r) {
            if ($r->status === 'error') {
                $hasErrors = true;
            }
            if ($r->isOk()) {
                $messages[] = $id . ': ok (' . $r->count . ' items)';
            } elseif ($r->status === 'skipped') {
                $messages[] = $id . ': skipped — ' . (string)($r->message ?? '');
            } else {
                $messages[] = $id . ': error — ' . (string)($r->message ?? '');
            }
        }

        $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
        echo json_encode([
            'ok' => !$hasErrors,
            'messages' => $messages,
            'elapsed_ms' => $elapsedMs,
        ]);
    }

    public function refreshPlugin(): void
    {
        if (!$this->guardPost()) {
            return;
        }

        $id = trim((string)($_POST['plugin_id'] ?? ''));
        $coreIds = [CoreRunner::ID_RSS, CoreRunner::ID_PARL_PRESS, CoreRunner::ID_SCRAPER, CoreRunner::ID_MAIL];
        $registry = new PluginRegistry();
        if ($id === '' || (!in_array($id, $coreIds, true) && !$registry->has($id))) {
            $_SESSION['error'] = 'Unknown plugin or core fetcher id.';
            $this->redirectToDiagnostics();

            return;
        }

        try {
            $pdo = getDbConnection();
            $refresh = RefreshAllService::boot($pdo);
            $result = in_array($id, $coreIds, true)
                ? $refresh->runCoreFetcher($id, true)
                : $refresh->runPlugin($id, true);
        } catch (\Throwable $e) {
            error_log('Seismo diagnostics refresh_plugin: ' . $e->getMessage());
            $_SESSION['error'] = 'Refresh ' . $id . ' failed: ' . $e->getMessage();
            $this->redirectToDiagnostics();

            return;
        }

        if ($result->isOk()) {
            $_SESSION['success'] = sprintf('Refresh %s: %d row(s) processed.', $id, $result->count);
        } elseif ($result->status === 'skipped') {
            $_SESSION['error'] = 'Refresh ' . $id . ' skipped: ' . ($result->message ?? '');
        } else {
            $_SESSION['error'] = 'Refresh ' . $id . ' failed: ' . ($result->message ?? 'unknown error');
        }

        $this->redirectToDiagnostics();
    }

    public function test(): void
    {
        if (!$this->guardPost()) {
            return;
        }

        $id = trim((string)($_POST['plugin_id'] ?? ''));
        if ($id === '' || !(new PluginRegistry())->has($id)) {
            $_SESSION['error'] = 'Unknown plugin id.';
            $this->redirectToDiagnostics();

            return;
        }

        try {
            $pdo = getDbConnection();
            $peek = RefreshAllService::boot($pdo)->testPlugin($id, 5);
        } catch (\Throwable $e) {
            error_log('Seismo diagnostics test: ' . $e->getMessage());
            $_SESSION['error'] = 'Test ' . $id . ' failed: ' . $e->getMessage();
            $this->redirectToDiagnostics();

            return;
        }

        $_SESSION['plugin_test_result'] = [
            'id'    => $id,
            'count' => $peek['count'],
            'error' => $peek['error'],
            'items' => $peek['items'],
        ];

        $this->redirectToDiagnostics();
    }

    private function guardPost(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirectToTarget($this->resolvePostReturnTarget());

            return false;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirectToTarget($this->resolvePostReturnTarget());

            return false;
        }

        return true;
    }

    /**
     * After `refresh_all`, honour optional `return_action` from the POST body
     * (dashboard “Refresh” posts `index`; Diagnostics omits the field).
     */
    private function redirectAfterRefresh(): void
    {
        $this->redirectToTarget($this->resolvePostReturnTarget());
    }

    private function resolvePostReturnTarget(): string
    {
        $t = trim((string)($_POST['return_action'] ?? ''));

        return $t === 'index' ? 'index' : 'diagnostics';
    }

    private function redirectToTarget(string $action): void
    {
        $a = $action === 'index' ? 'index' : 'diagnostics';
        header('Location: ' . getBasePath() . '/index.php?action=' . rawurlencode($a), true, 303);
        exit;
    }

    private function redirectToDiagnostics(): void
    {
        header('Location: ' . getBasePath() . '/index.php?action=diagnostics', true, 303);
        exit;
    }
}
