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
 * The default action stays on `health` until the dashboard lands in Slice 1.
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
    'health',
    \Seismo\Controller\HealthController::class . '::show',
    true
);
$router->setDefault('health');

$action = $_GET['action'] ?? '';
if (!is_string($action)) {
    $action = '';
}
$router->dispatch($action);
