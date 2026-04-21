<?php
/**
 * Satellite-only route table — timeline, highlights, in-app Label training UI,
 * settings (general + Magnitu), Magnitu Bearer API, remote “refresh mothership” POST, auth, health, migrate.
 * No feeds, Lex/Leg admin, diagnostics UI, retention, exports, or other
 * mothership-only surfaces.
 *
 * @var \Seismo\Http\Router $router
 */

declare(strict_types=1);

$router->register(
    'index',
    \Seismo\Controller\DashboardController::class . '::show',
    true
);
$router->register(
    'filter',
    \Seismo\Controller\DashboardController::class . '::showFilter',
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
    'refresh_remote',
    \Seismo\Controller\DashboardController::class . '::refreshRemote',
    false
);
$router->register(
    'toggle_favourite',
    \Seismo\Controller\FavouriteController::class . '::toggle',
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
$router->register(
    'settings',
    \Seismo\Controller\SettingsController::class . '::show',
    true
);
$router->register(
    'settings_save',
    \Seismo\Controller\SettingsController::class . '::saveGeneral',
    false
);
$router->register(
    'settings_save_magnitu',
    \Seismo\Controller\MagnituAdminController::class . '::saveConfig',
    false
);
$router->register(
    'settings_regenerate_magnitu_key',
    \Seismo\Controller\MagnituAdminController::class . '::regenerateKey',
    false
);
$router->register(
    'settings_clear_magnitu_scores',
    \Seismo\Controller\MagnituAdminController::class . '::clearScores',
    false
);
$router->register(
    'magnitu',
    \Seismo\Controller\MagnituHighlightsController::class . '::show',
    true
);
$router->register(
    'label',
    \Seismo\Controller\MagnituLabelUiController::class . '::show',
    true
);
$router->register(
    'label_save',
    \Seismo\Controller\MagnituLabelUiController::class . '::save',
    false
);
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
