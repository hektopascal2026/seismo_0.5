<?php
/**
 * Seismo schema migrator (CLI only).
 *
 * Runs the DDL that used to live inside `initDatabase()` on every HTTP
 * request. In 0.5 the schema changes happen explicitly, triggered by the
 * operator after an upload or deploy.
 *
 * Usage:
 *   php migrate.php           # apply any pending migrations
 *   php migrate.php --status  # print the current schema version and exit
 *
 * Actual migration steps are ported slice by slice from 0.4's initDatabase().
 * See docs/consolidation-plan.md for the order of work.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "migrate.php is a CLI tool. Run it from a terminal:\n\n  php migrate.php\n";
    exit(1);
}

require __DIR__ . '/bootstrap.php';

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

$currentSchema = null;
try {
    $stmt = $pdo->query(
        "SELECT config_value FROM magnitu_config WHERE config_key = 'schema_version'"
    );
    $currentSchema = $stmt ? $stmt->fetchColumn() : false;
    if ($currentSchema === false) {
        $currentSchema = null;
    }
} catch (Throwable $e) {
    // magnitu_config doesn't exist yet on a brand-new database.
    $currentSchema = null;
}

echo "Current schema version: " . ($currentSchema === null ? 'uninitialised' : (int)$currentSchema) . "\n";

if ($statusOnly) {
    exit(0);
}

// -----------------------------------------------------------------------------
// Migrations are intentionally not defined yet. Each slice that needs schema
// will append here (or in a src/Migration/*.php class registered below), and
// this script will apply them idempotently.
// -----------------------------------------------------------------------------
echo "No migrations defined yet — see docs/consolidation-plan.md.\n";
echo "Done.\n";
