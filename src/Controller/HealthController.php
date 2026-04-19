<?php
/**
 * Health check — the first route in 0.5.
 *
 * Intentionally tiny. Confirms the bootstrap works end-to-end:
 *   - PHP is able to load bootstrap.php and the autoloader.
 *   - config.local.php is present and defines DB credentials.
 *   - The database is reachable.
 *   - Satellite / mothership mode is detected correctly.
 *
 * Also reports the current schema version so operators can tell whether
 * `php migrate.php` still needs to run. Once real features land this route
 * stays useful for uptime checks.
 */

declare(strict_types=1);

namespace Seismo\Controller;

final class HealthController
{
    public function show(): void
    {
        $dbStatus      = 'unknown';
        $dbVersion     = null;
        $schemaVersion = null;

        try {
            $pdo = getDbConnection();
            $dbStatus  = 'ok';
            $dbVersion = (string)$pdo->query('SELECT VERSION()')->fetchColumn();

            // The schema_version row lives in magnitu_config, which may not
            // exist yet on a fresh install. Treat that as "not migrated".
            try {
                $stmt = $pdo->query(
                    "SELECT config_value FROM magnitu_config WHERE config_key = 'schema_version'"
                );
                $schemaVersion = $stmt ? $stmt->fetchColumn() : null;
                if ($schemaVersion === false) {
                    $schemaVersion = null;
                }
            } catch (\Throwable $e) {
                $schemaVersion = null;
            }
        } catch (\Throwable $e) {
            $dbStatus = 'error: ' . $e->getMessage();
        }

        $data = [
            'seismoVersion' => SEISMO_VERSION,
            'phpVersion'    => PHP_VERSION,
            'dbStatus'      => $dbStatus,
            'dbVersion'     => $dbVersion,
            'schemaVersion' => $schemaVersion,
            'satellite'     => isSatellite(),
            'mothershipDb'  => SEISMO_MOTHERSHIP_DB,
            'brandTitle'    => seismoBrandTitle(),
            'basePath'      => getBasePath(),
        ];

        require SEISMO_ROOT . '/views/health.php';
    }
}
