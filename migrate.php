<?php
/**
 * Seismo schema migrator (CLI only).
 *
 * Applies versioned migrations in `src/Migration/`. The base migration (17)
 * loads `docs/db-schema.sql` (consolidated 0.4 schema, all CREATE IF NOT EXISTS).
 *
 * Safe on your live database: if `magnitu_config.schema_version` is already 17,
 * nothing runs except a quick version check.
 *
 * Usage:
 *   php migrate.php           # apply pending migrations
 *   php migrate.php --status  # print current schema version and exit
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "migrate.php is a CLI tool. Run it from a terminal:\n\n  php migrate.php\n";
    exit(1);
}

require __DIR__ . '/bootstrap.php';

use Seismo\Migration\MigrationRunner;

$statusOnly = in_array('--status', $argv, true);

echo "Seismo migrate — " . SEISMO_VERSION . "\n";

try {
    $pdo = getDbConnection();
} catch (Throwable $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    exit(2);
}

$dbVersion = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
echo "Connected to MySQL {$dbVersion}\n";

$runner = new MigrationRunner($pdo);
$current = $runner->getCurrentVersion();
echo "Current schema version: {$current}\n";

if ($statusOnly) {
    echo "Latest built-in migration: " . MigrationRunner::LATEST_VERSION . "\n";
    exit(0);
}

if ($current >= MigrationRunner::LATEST_VERSION) {
    echo "Nothing to do — schema is already at version " . MigrationRunner::LATEST_VERSION . ".\n";
    exit(0);
}

try {
    $runner->run(static function (string $line): void {
        echo $line;
    });
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(3);
}

echo "Done.\n";
exit(0);
