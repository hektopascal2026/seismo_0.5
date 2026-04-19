<?php
/**
 * Run database migrations over HTTP for hosts without SSH / PHP CLI.
 *
 * Protected by SEISMO_MIGRATE_KEY (same pattern as FEED_DIAGNOSTIC_KEY).
 * Disable by leaving the constant unset or empty — then only `php migrate.php`
 * works (for developers with CLI).
 */

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Migration\MigrationRunner;
use Throwable;

final class MigrateController
{
    public function runWeb(): void
    {
        header('Content-Type: text/plain; charset=utf-8');

        if (!defined('SEISMO_MIGRATE_KEY') || SEISMO_MIGRATE_KEY === '') {
            http_response_code(403);
            echo "Web migrations are disabled (SEISMO_MIGRATE_KEY not set in config.local.php).\n";
            echo "If you have shell access, run: php migrate.php\n";
            return;
        }

        $key = $_GET['key'] ?? '';
        if (!is_string($key) || $key === '' || !hash_equals((string)SEISMO_MIGRATE_KEY, $key)) {
            http_response_code(403);
            echo "Forbidden.\n";
            return;
        }

        try {
            $pdo = getDbConnection();
            $runner = new MigrationRunner($pdo);
            $current = $runner->getCurrentVersion();

            echo 'Seismo migrate — ' . SEISMO_VERSION . "\n";
            echo "Current schema version: {$current}\n";

            if ($current >= MigrationRunner::LATEST_VERSION) {
                echo 'Nothing to do — schema is already at version ' . MigrationRunner::LATEST_VERSION . ".\n";
                return;
            }

            $runner->run(static function (string $line): void {
                echo $line;
            });
            echo "Done.\n";
        } catch (Throwable $e) {
            http_response_code(500);
            echo 'Migration failed: ' . $e->getMessage() . "\n";
        }
    }
}
