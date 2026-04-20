<?php

declare(strict_types=1);

namespace Seismo\Service;

use DateTimeImmutable;
use DateTimeZone;
use Seismo\Config\CalendarConfigStore;
use Seismo\Config\LexConfigStore;
use Seismo\Core\Scoring\ScoringService;
use Seismo\Repository\CalendarEventRepository;
use Seismo\Repository\EmailIngestRepository;
use Seismo\Repository\EntryScoreRepository;
use Seismo\Repository\FeedItemRepository;
use Seismo\Repository\LexItemRepository;
use Seismo\Repository\SystemConfigRepository;
use Seismo\Repository\PluginRunLogRepository;

/**
 * Orchestrates plugin execution. Shared by:
 *   - Master cron (refresh_cron.php) — calls runAll() with throttling.
 *   - Web "Refresh all" button (?action=refresh_all) — calls runAll(force: true).
 *   - Per-plugin refresh buttons — calls runPlugin($id, force: true).
 *   - Diagnostics "Test" button — calls testPlugin($id) (no persistence).
 *
 * ## Master Cron pattern
 *
 * `refresh_cron.php` is the ONLY cron job a shared-host admin needs to register.
 * It may fire every 5 minutes (Plesk default granularity). We do NOT want every
 * plugin hitting its upstream every 5 minutes, so runAll() consults each
 * plugin's {@see SourceFetcherInterface::getMinIntervalSeconds()} and skips
 * plugins whose last `ok` run in `plugin_run_log` is fresher than that.
 *
 * Throttle skips use {@see PluginRunResult::throttleSkipped()} — they are **not**
 * persisted to `plugin_run_log` (cron stdout only). User-initiated refresh paths
 * call with `$force = true` to bypass the throttle.
 *
 * Rows ARE persisted for every non-throttle outcome (ok, error, skipped-because-
 * satellite, skipped-because-disabled-in-config). Those are the rows diagnostics
 * displays.
 *
 * Slice 4: {@see CoreRunner} runs first (RSS/Substack, scraper, IMAP mail),
 * then registered plugins. Same `runAll()` entry point for web + CLI cron.
 *
 * After ingest, {@see recipeRescoreAfterIngest()} runs the deterministic recipe
 * scorer ({@see ScoringService}) so new rows get `entry_scores` without waiting
 * for a Magnitu `magnitu_recipe` POST.
 */
final class RefreshAllService
{
    public function __construct(
        private readonly PluginRegistry $registry,
        private readonly PluginRunLogRepository $runLog,
        private readonly LexItemRepository $lexItems,
        private readonly CalendarEventRepository $calendarEvents,
        private readonly LexConfigStore $lexConfig,
        private readonly CalendarConfigStore $calendarConfig,
        private readonly CoreRunner $coreRunner,
        private readonly SystemConfigRepository $systemConfig,
        private readonly EntryScoreRepository $entryScores,
    ) {
    }

    /**
     * Run core fetchers, then every registered plugin.
     *
     * @param bool $force If true, ignore the per-plugin throttle (web "Refresh all").
     * @return array<string, PluginRunResult>
     */
    public function runAll(bool $force = false): array
    {
        $results = $this->coreRunner->runAll($force);
        foreach ($this->registry->all() as $id => $plugin) {
            $results[$id] = $this->runOne($plugin, $force);
        }

        $this->recipeRescoreAfterIngest();

        return $results;
    }

    /**
     * Run a single plugin by id.
     *
     * @param bool $force Defaults to true because the web single-plugin refresh
     *                    button is always explicit human intent.
     */
    public function runPlugin(string $id, bool $force = true): PluginRunResult
    {
        $plugin = $this->registry->get($id);
        if ($plugin === null) {
            return PluginRunResult::error('Plugin "' . $id . '" is not registered.');
        }

        $result = $this->runOne($plugin, $force);
        $this->recipeRescoreAfterIngest();

        return $result;
    }

    /**
     * Dry-run for diagnostics: call fetch() without writing. Throttle is
     * ignored; no plugin_run_log row is written. Returns the first $peek rows.
     *
     * @return array{items: list<array<string, mixed>>, error: ?string, count: int}
     */
    public function testPlugin(string $id, int $peek = 5): array
    {
        $peek = max(1, min($peek, 20));
        $plugin = $this->registry->get($id);
        if ($plugin === null) {
            return ['items' => [], 'error' => 'Plugin "' . $id . '" is not registered.', 'count' => 0];
        }

        if (isSatellite()) {
            return ['items' => [], 'error' => 'Satellite mode — entry plugins do not run here.', 'count' => 0];
        }

        $block = $this->resolveConfigBlock($plugin);

        try {
            $rows = $plugin->fetch($block);
        } catch (\Throwable $e) {
            error_log('Seismo testPlugin ' . $plugin->getIdentifier() . ': ' . $e->getMessage());

            return ['items' => [], 'error' => $e->getMessage(), 'count' => 0];
        }

        return [
            'items' => array_slice($rows, 0, $peek),
            'error' => null,
            'count' => count($rows),
        ];
    }

    private function runOne(SourceFetcherInterface $plugin, bool $force): PluginRunResult
    {
        $id = $plugin->getIdentifier();

        if (isSatellite()) {
            $result = PluginRunResult::skipped('Satellite mode — entry plugins do not run here.');
            $this->record($id, $result, 0);

            return $result;
        }

        if (!$force && $this->isThrottled($plugin)) {
            $msg = 'Throttled — last successful run is fresher than ' . $plugin->getMinIntervalSeconds() . 's.';

            return PluginRunResult::throttleSkipped($msg);
        }

        $block = $this->resolveConfigBlock($plugin);
        if (empty($block['enabled'])) {
            $result = PluginRunResult::skipped('Disabled in config.');
            $this->record($id, $result, 0);

            return $result;
        }

        $start = (int)(microtime(true) * 1000);
        try {
            $rows = $plugin->fetch($block);
            $count = $this->persist($plugin, $rows);
            $result = PluginRunResult::ok($count);
        } catch (\Throwable $e) {
            error_log('Seismo plugin ' . $id . ': ' . $e->getMessage());
            $result = PluginRunResult::error($e->getMessage());
        }
        $duration = max(0, (int)(microtime(true) * 1000) - $start);
        $this->record($id, $result, $duration);

        return $result;
    }

    private function isThrottled(SourceFetcherInterface $plugin): bool
    {
        $minInterval = $plugin->getMinIntervalSeconds();
        if ($minInterval <= 0) {
            return false;
        }
        $last = $this->runLog->lastSuccessfulRunAt($plugin->getIdentifier());
        if ($last === null) {
            return false;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return ($now->getTimestamp() - $last->getTimestamp()) < $minInterval;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveConfigBlock(SourceFetcherInterface $plugin): array
    {
        $key = $plugin->getConfigKey();

        return match ($plugin->getEntryType()) {
            'lex_item'       => (array)($this->lexConfig->load()[$key] ?? []),
            'calendar_event' => (array)($this->calendarConfig->load()[$key] ?? []),
            default          => [],
        };
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function persist(SourceFetcherInterface $plugin, array $rows): int
    {
        return match ($plugin->getEntryType()) {
            'lex_item'       => $this->lexItems->upsertBatch($rows),
            'calendar_event' => $this->calendarEvents->upsertBatch($rows),
            default          => throw new \RuntimeException('No repository wired for entry_type "' . $plugin->getEntryType() . '"'),
        };
    }

    private function record(string $id, PluginRunResult $result, int $durationMs): void
    {
        if (!$result->persistToPluginRunLog) {
            return;
        }
        try {
            $this->runLog->record($id, $result, $durationMs);
        } catch (\Throwable $e) {
            error_log('Seismo plugin_run_log write failed: ' . $e->getMessage());
        }
    }

    /**
     * Run a single core fetcher (`core:rss`, `core:parl_press`, `core:scraper`, `core:mail`).
     */
    public function runCoreFetcher(string $coreId, bool $force = true): PluginRunResult
    {
        $result = $this->coreRunner->runOne($coreId, $force);
        $this->recipeRescoreAfterIngest();

        return $result;
    }

    /**
     * Best-effort recipe scoring for rows without a Magnitu score. Does not
     * affect plugin exit codes or flash messages when it fails.
     */
    private function recipeRescoreAfterIngest(): void
    {
        try {
            $raw = $this->systemConfig->get('recipe_json');
            if ($raw === null || $raw === '') {
                return;
            }
            $recipe = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($recipe)) {
                return;
            }
            $scorer = new ScoringService($this->entryScores);
            $scorer->rescoreAll($recipe);
        } catch (\Throwable $e) {
            error_log('Seismo recipe rescore after refresh: ' . $e->getMessage());
        }
    }

    /**
     * Convenience factory so controllers and cron don't repeat the wiring.
     */
    public static function boot(\PDO $pdo): self
    {
        $runLog       = new PluginRunLogRepository($pdo);
        $systemConfig = new SystemConfigRepository($pdo);

        return new self(
            new PluginRegistry(),
            $runLog,
            new LexItemRepository($pdo),
            new CalendarEventRepository($pdo),
            new LexConfigStore(),
            new CalendarConfigStore(),
            new CoreRunner(
                new FeedItemRepository($pdo),
                $runLog,
                $systemConfig,
                new EmailIngestRepository($pdo),
            ),
            $systemConfig,
            new EntryScoreRepository($pdo),
        );
    }
}
