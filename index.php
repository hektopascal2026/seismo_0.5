<?php
/**
 * Seismo 0.5 front controller.
 *
 * Kept thin on purpose. All it does is:
 *   - bootstrap the app,
 *   - build a route table,
 *   - dispatch the request.
 *
 * Each feature that gets ported in later slices registers its routes here.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', '0');

$__action = $_GET['action'] ?? '';
if (!is_string($__action)) {
    $__action = '';
}
// Anonymous `?action=health` uptime checks rarely send a cookie; skip
// session_start() so we do not allocate a session file per poll. Every other
// route (and health with an existing session cookie) still opens the session.
$__sessName = session_name();
if ($__action !== 'health' || ($__sessName !== '' && !empty($_COOKIE[$__sessName]))) {
    session_start();
}

require __DIR__ . '/bootstrap.php';

use Seismo\Http\AuthGate;
use Seismo\Http\Router;

$router = new Router();

$router->register(
    'index',
    \Seismo\Controller\DashboardController::class . '::show',
    true
);
$router->register(
    'health',
    \Seismo\Controller\HealthController::class . '::show',
    true
);
$router->register(
    'migrate',
    \Seismo\Controller\MigrateController::class . '::runWeb',
    true
);
$router->register(
    'toggle_favourite',
    \Seismo\Controller\FavouriteController::class . '::toggle',
    false
);
$router->register(
    'lex',
    \Seismo\Controller\LexController::class . '::show',
    true
);
$router->register(
    'refresh_fedlex',
    \Seismo\Controller\LexController::class . '::refreshFedlex',
    false
);
$router->register(
    'save_lex_ch',
    \Seismo\Controller\LexController::class . '::saveLexCh',
    false
);
$router->register(
    'leg',
    \Seismo\Controller\LegController::class . '::show',
    true
);
// Legacy ?action=calendar URL — redirect to ?action=leg (same slug 0.4 used).
$router->register(
    'calendar',
    \Seismo\Controller\LegController::class . '::show',
    true
);
$router->register(
    'refresh_parl_ch',
    \Seismo\Controller\LegController::class . '::refreshParlCh',
    false
);
$router->register(
    'save_leg_parl_ch',
    \Seismo\Controller\LegController::class . '::saveLegParlCh',
    false
);
$router->register(
    'refresh_all',
    \Seismo\Controller\DiagnosticsController::class . '::refreshAll',
    false
);
$router->register(
    'refresh_plugin',
    \Seismo\Controller\DiagnosticsController::class . '::refreshPlugin',
    false
);
$router->register(
    'plugin_test',
    \Seismo\Controller\DiagnosticsController::class . '::test',
    false
);
$router->register(
    'diagnostics',
    \Seismo\Controller\DiagnosticsController::class . '::show',
    false
);
$router->register(
    'login',
    \Seismo\Controller\AuthController::class . '::showLogin',
    false
);
$router->register(
    'logout',
    \Seismo\Controller\AuthController::class . '::logout',
    false
);
// Retention settings surface. Read view is plain GET; preview and
// actual prune are POSTed with a session-bound CSRF token.
$router->register(
    'retention',
    \Seismo\Controller\RetentionController::class . '::show',
    true
);
$router->register(
    'retention_preview',
    \Seismo\Controller\RetentionController::class . '::preview',
    false
);
$router->register(
    'retention_save',
    \Seismo\Controller\RetentionController::class . '::save',
    false
);
$router->register(
    'retention_prune',
    \Seismo\Controller\RetentionController::class . '::runPrune',
    false
);
// Magnitu HTTP API — Bearer-authenticated against `system_config.api_key`.
// AuthGate whitelists these so dormant-by-default session auth doesn't
// intercept them; BearerAuth inside the controller enforces the real gate.
$router->register(
    'magnitu_entries',
    \Seismo\Controller\MagnituController::class . '::entries',
    true
);
$router->register(
    'magnitu_scores',
    \Seismo\Controller\MagnituController::class . '::scores',
    true
);
$router->register(
    'magnitu_recipe',
    \Seismo\Controller\MagnituController::class . '::recipe',
    true
);
$router->register(
    'magnitu_labels',
    \Seismo\Controller\MagnituController::class . '::labels',
    true
);
$router->register(
    'magnitu_status',
    \Seismo\Controller\MagnituController::class . '::status',
    true
);
// Read-only export surface — Bearer-authenticated against `export:api_key`
// only (briefing scripts and automation cannot push scores or labels).
$router->register(
    'export_entries',
    \Seismo\Controller\ExportController::class . '::entries',
    true
);
$router->register(
    'export_briefing',
    \Seismo\Controller\ExportController::class . '::briefing',
    true
);
$router->setDefault('index');

$action = $__action;

// POST to ?action=login uses showLogin's own path (the controller branches on
// REQUEST_METHOD), so overlay the handler only on POST.
if ($action === 'login' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $router->register('login', \Seismo\Controller\AuthController::class . '::handleLogin', false);
}

// Dormant-by-default auth gate — runs before dispatch. When
// SEISMO_ADMIN_PASSWORD_HASH is empty/unset this is a no-op.
AuthGate::check($action);

$router->dispatch($action);
