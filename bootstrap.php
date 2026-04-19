<?php
/**
 * Seismo bootstrap.
 *
 * Responsibilities (kept intentionally small):
 *   1. Load local credentials from config.local.php.
 *   2. Define SEISMO_* constants (satellite/brand knobs) with safe defaults.
 *   3. Register a minimal PSR-4 autoloader for Seismo\* classes under src/.
 *   4. Provide a handful of global helpers that every layer depends on:
 *      getDbConnection(), getBasePath(), isSatellite(), entryTable(),
 *      entryDbSchemaExpr(), seismoBrandTitle(), seismoBrandAccent().
 *
 * Anything larger (DDL, scoring, feature config) lives in its own module or
 * migration file. See docs/consolidation-plan.md.
 */

declare(strict_types=1);

define('SEISMO_VERSION', '0.5.0-dev');
define('SEISMO_ROOT', __DIR__);

// ---------------------------------------------------------------------------
// 1. Local credentials
// ---------------------------------------------------------------------------
$__seismoLocalConfig = __DIR__ . '/config.local.php';
if (!file_exists($__seismoLocalConfig)) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Missing config.local.php — copy config.local.php.example and fill in your database credentials.\n");
        exit(1);
    }
    http_response_code(503);
    die('Missing config.local.php — copy config.local.php.example and fill in your database credentials.');
}
require $__seismoLocalConfig;
unset($__seismoLocalConfig);

// ---------------------------------------------------------------------------
// 2. SEISMO_* defaults (satellite / branding / remote refresh)
// ---------------------------------------------------------------------------
$__seismoDefaults = [
    'SEISMO_MOTHERSHIP_DB'     => '',
    'SEISMO_SATELLITE_MODE'    => false,
    'SEISMO_BRAND_TITLE'       => '',
    'SEISMO_BRAND_ACCENT'      => '',
    'SEISMO_MOTHERSHIP_URL'    => '',
    'SEISMO_REMOTE_REFRESH_KEY' => '',
    'FEED_DIAGNOSTIC_KEY'      => '',
];
foreach ($__seismoDefaults as $__c => $__v) {
    if (!defined($__c)) {
        define($__c, $__v);
    }
}
unset($__seismoDefaults, $__c, $__v);

// ---------------------------------------------------------------------------
// 3. Autoloader for Seismo\* → src/
// ---------------------------------------------------------------------------
spl_autoload_register(static function (string $class): void {
    $prefix = 'Seismo\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

// ---------------------------------------------------------------------------
// 4. Global helpers
// ---------------------------------------------------------------------------

/**
 * PDO singleton. One connection per request.
 *
 * Throws PDOException on failure — callers decide how to present the error
 * (HTTP 503 vs CLI stderr). See `migrate.php` and `index.php` for both styles.
 */
function getDbConnection(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

/**
 * Base URL path for web-relative links. Derived from PHP_SELF so Seismo can
 * live at `/` or inside a subfolder like `/seismo/` without code changes.
 *
 * Returns '' for root installs, otherwise the leading path (no trailing slash).
 * MUST be used for every internal href/redirect — never hardcode hostnames.
 */
function getBasePath(): string
{
    $phpSelf = $_SERVER['PHP_SELF'] ?? '';
    $path = dirname($phpSelf);
    if ($path === '/' || $path === '\\' || $path === '.' || $path === '') {
        return '';
    }
    return rtrim(str_replace('\\', '/', $path), '/');
}

/**
 * True when this instance is a lightweight satellite.
 *
 * Satellites read entries cross-DB from a mothership and only keep scoring
 * tables locally. Used to hide admin/fetcher UI and short-circuit write routes.
 */
function isSatellite(): bool
{
    return SEISMO_SATELLITE_MODE === true;
}

/**
 * SQL reference for an entry-source table.
 *
 * Local mode  → bare table name.
 * Satellite   → `mothership_db`.table  for cross-DB reads.
 *
 * Use for entry-source tables only: feed_items, feeds, lex_items,
 * calendar_events (Leg), sender_tags, email_subscriptions, and the email
 * table. NEVER use for entry_scores, magnitu_config, magnitu_labels — those
 * are always local to each instance.
 */
function entryTable(string $table): string
{
    if (SEISMO_MOTHERSHIP_DB !== '') {
        return '`' . SEISMO_MOTHERSHIP_DB . '`.' . $table;
    }
    return $table;
}

/**
 * SQL expression for the schema that holds entry tables.
 * Used inline in INFORMATION_SCHEMA queries.
 */
function entryDbSchemaExpr(): string
{
    if (SEISMO_MOTHERSHIP_DB !== '') {
        return "'" . addslashes(SEISMO_MOTHERSHIP_DB) . "'";
    }
    return 'DATABASE()';
}

/**
 * Top-bar title. Defaults to "Seismo"; satellites override via SEISMO_BRAND_TITLE.
 */
function seismoBrandTitle(): string
{
    return SEISMO_BRAND_TITLE !== '' ? (string)SEISMO_BRAND_TITLE : 'Seismo';
}

/**
 * Optional per-satellite accent colour (hex). Null when not configured.
 */
function seismoBrandAccent(): ?string
{
    return SEISMO_BRAND_ACCENT !== '' ? (string)SEISMO_BRAND_ACCENT : null;
}
