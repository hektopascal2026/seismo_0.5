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

session_start();

require __DIR__ . '/bootstrap.php';

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
$router->setDefault('index');

$action = $_GET['action'] ?? '';
if (!is_string($action)) {
    $action = '';
}
$router->dispatch($action);
