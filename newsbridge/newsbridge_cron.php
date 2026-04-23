<?php
/**
 * Newsbridge — generate static RSS files under `newsbridge/feeds/` for Seismo
 * to poll like any other `feeds.url` (replaces generated XML on staging).
 *
 * CLI only. Run on a schedule separate from `refresh_cron.php` (e.g. every
 * 10–30 minutes), then point your four feed rows at this install, e.g.:
 *   https://www.example.org/seismo/newsbridge/feeds/top-ch.xml
 * (use your real host and base path). Update the same URLs in **Feeds** after
 * deploy so they no longer reference `/seismo-staging/newsbridge/...`.
 *
 *   php /path/to/seismo_0.5/newsbridge/newsbridge_cron.php
 *
 * @noinspection PhpUnhandledExceptionInspection
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "newsbridge_cron.php is CLI-only.\n";
    exit(1);
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Seismo\Service\NewsbridgeGenerator;

$configPath = SEISMO_ROOT . '/newsbridge/config.json';
$outDir     = SEISMO_ROOT . '/newsbridge/feeds';

$gen  = new NewsbridgeGenerator();
$report = $gen->run($configPath, $outDir);

foreach ($report['errors'] as $err) {
    fwrite(STDERR, "[newsbridge] " . $err . "\n");
}

foreach ($report['stats'] as $file => $st) {
    echo '[newsbridge] ' . $file
        . ' items=' . (int)$st['items']
        . ' sources=' . (int)$st['sources']
        . ' source_errors=' . (int)$st['failed_sources']
        . "\n";
}

if ($report['written'] === [] && $report['errors'] !== []) {
    exit(1);
}

exit(0);
