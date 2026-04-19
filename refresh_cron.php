<?php
/**
 * Master Cron for Seismo 0.5.
 *
 * This is the ONLY cron job a shared-host admin needs to register. Suggested
 * Plesk entry (runs every 5 minutes):
 *
 *   *\/5 * * * *  /usr/bin/php /path/to/seismo/refresh_cron.php
 *
 * The script is a thin shell around RefreshAllService::runAll(). Per-plugin
 * throttling lives inside the service: plugins whose
 * getMinIntervalSeconds() hasn't elapsed since the last successful run are
 * skipped silently (stdout only, no DB row). Anything else — success, error,
 * "satellite mode", "disabled in config" — is persisted to plugin_run_log
 * and visible at ?action=diagnostics.
 *
 * Hard rules:
 *   - CLI only. A browser-triggered run would be a DoS vector; we refuse.
 *   - Satellite mode is a no-op. Satellites read entries cross-DB from the
 *     mothership; they have no upstreams to refresh.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "refresh_cron.php is CLI-only. Use ?action=refresh_all from the web UI (protected by auth + CSRF).\n";
    exit(1);
}

require_once __DIR__ . '/bootstrap.php';

use Seismo\Service\RefreshAllService;

if (isSatellite()) {
    fwrite(STDOUT, "[seismo] satellite mode — refresh_cron skipped.\n");
    exit(0);
}

try {
    $pdo = getDbConnection();
} catch (\Throwable $e) {
    fwrite(STDERR, '[seismo] DB connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$start = microtime(true);
fwrite(STDOUT, '[seismo] master cron tick @ ' . gmdate('Y-m-d\TH:i:s\Z') . "\n");

$results = RefreshAllService::boot($pdo)->runAll(false);

$errorCount = 0;
foreach ($results as $result) {
    if ($result->status === 'error') {
        $errorCount++;
    }
}

foreach ($results as $id => $result) {
    $line = sprintf(
        '[seismo] plugin %-10s %-8s %s%s',
        $id,
        $result->status,
        $result->status === 'ok' ? ('count=' . $result->count) : '',
        $result->message !== null ? ' msg=' . $result->message : ''
    );
    fwrite(STDOUT, $line . "\n");
}

$duration = (int)((microtime(true) - $start) * 1000);
fwrite(STDOUT, "[seismo] master cron done in {$duration}ms\n");
if ($errorCount > 0) {
    fwrite(STDERR, "[seismo] master cron exiting with code 2 ({$errorCount} plugin error(s)).\n");
    exit(2);
}
exit(0);
