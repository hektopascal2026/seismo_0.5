<?php

declare(strict_types=1);

namespace Seismo\Service;

use DateTimeImmutable;
use DateTimeZone;
use Seismo\Core\Fetcher\ImapMailFetchService;
use Seismo\Core\Fetcher\ParlPressFetchService;
use Seismo\Core\Fetcher\RssFetchService;
use Seismo\Core\Fetcher\ScraperFetchService;
use Seismo\Repository\EmailIngestRepository;
use Seismo\Repository\FeedItemRepository;
use Seismo\Repository\PluginRunLogRepository;
use Seismo\Repository\SystemConfigRepository;

/**
 * Core upstreams (RSS, scraper, mail) — not SourceFetcherInterface plugins.
 * Writes {@see PluginRunResult}s under synthetic ids {@see self::CORE_IDS}.
 */
final class CoreRunner
{
    public const ID_RSS        = 'core:rss';
    public const ID_PARL_PRESS = 'core:parl_press';
    public const ID_SCRAPER    = 'core:scraper';
    public const ID_MAIL       = 'core:mail';

    /** @var array<string, int> seconds between successful runs when not forced */
    private const THROTTLE_SECONDS = [
        self::ID_RSS         => 1800,
        self::ID_PARL_PRESS  => 1800,
        self::ID_SCRAPER     => 3600,
        self::ID_MAIL        => 900,
    ];

    public function __construct(
        private FeedItemRepository $feeds,
        private PluginRunLogRepository $runLog,
        private SystemConfigRepository $magnituConfig,
        private EmailIngestRepository $emailIngest,
        private RssFetchService $rss = new RssFetchService(),
        private ScraperFetchService $scraper = new ScraperFetchService(),
        private ParlPressFetchService $parlPress = new ParlPressFetchService(),
        private ImapMailFetchService $imapMail = new ImapMailFetchService(),
    ) {
    }

    /**
     * @return array<string, PluginRunResult>
     */
    public function runAll(bool $force): array
    {
        return [
            self::ID_RSS         => $this->runRss($force),
            self::ID_PARL_PRESS  => $this->runParlPress($force),
            self::ID_SCRAPER     => $this->runScraper($force),
            self::ID_MAIL        => $this->runMail($force),
        ];
    }

    /**
     * Run one core fetcher by id ({@see self::ID_RSS}, {@see self::ID_SCRAPER}, {@see self::ID_MAIL}).
     */
    public function runOne(string $coreId, bool $force): PluginRunResult
    {
        return match ($coreId) {
            self::ID_RSS        => $this->runRss($force),
            self::ID_PARL_PRESS => $this->runParlPress($force),
            self::ID_SCRAPER    => $this->runScraper($force),
            self::ID_MAIL       => $this->runMail($force),
            default             => PluginRunResult::error('Unknown core fetcher id: ' . $coreId),
        };
    }

    private function runRss(bool $force): PluginRunResult
    {
        if (isSatellite()) {
            $r = PluginRunResult::skipped('Satellite mode — core fetchers do not run here.');
            $this->record(self::ID_RSS, $r, 0);

            return $r;
        }
        if (!$force && $this->isThrottled(self::ID_RSS, self::THROTTLE_SECONDS[self::ID_RSS])) {
            return PluginRunResult::throttleSkipped(
                'Throttled — last successful run is fresher than ' . self::THROTTLE_SECONDS[self::ID_RSS] . 's.'
            );
        }

        $start = (int)(microtime(true) * 1000);
        $total = 0;
        try {
            $offset = 0;
            $page   = 200;
            while (true) {
                $batch = $this->feeds->listFeedsForRssRefresh($page, $offset);
                if ($batch === []) {
                    break;
                }
                foreach ($batch as $feed) {
                    $id = (int)($feed['id'] ?? 0);
                    $url = trim((string)($feed['url'] ?? ''));
                    if ($id <= 0 || $url === '') {
                        continue;
                    }
                    try {
                        $items = $this->rss->fetchFeedItems($url);
                        $n = $this->feeds->upsertFeedItems($id, $items);
                        $total += $n;
                        $this->feeds->touchFeedSuccess($id);
                    } catch (\Throwable $e) {
                        error_log('Seismo core:rss feed ' . $id . ': ' . $e->getMessage());
                        $this->feeds->touchFeedFailure($id, $e->getMessage());
                    }
                }
                if (count($batch) < $page) {
                    break;
                }
                $offset += $page;
            }
            $r = PluginRunResult::ok($total);
        } catch (\Throwable $e) {
            error_log('Seismo core:rss: ' . $e->getMessage());
            $r = PluginRunResult::error($e->getMessage());
        }
        $duration = max(0, (int)(microtime(true) * 1000) - $start);
        $this->record(self::ID_RSS, $r, $duration);

        return $r;
    }

    private function runParlPress(bool $force): PluginRunResult
    {
        if (isSatellite()) {
            $r = PluginRunResult::skipped('Satellite mode — core fetchers do not run here.');
            $this->record(self::ID_PARL_PRESS, $r, 0);

            return $r;
        }
        if (!$force && $this->isThrottled(self::ID_PARL_PRESS, self::THROTTLE_SECONDS[self::ID_PARL_PRESS])) {
            return PluginRunResult::throttleSkipped(
                'Throttled — last successful run is fresher than ' . self::THROTTLE_SECONDS[self::ID_PARL_PRESS] . 's.'
            );
        }

        $start = (int)(microtime(true) * 1000);
        $total = 0;
        try {
            $offset = 0;
            $page   = 50;
            while (true) {
                $batch = $this->feeds->listFeedsForParlPressRefresh($page, $offset);
                if ($batch === []) {
                    break;
                }
                foreach ($batch as $feed) {
                    $id = (int)($feed['id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    try {
                        // Always strip legacy rows (e.g. SimplePie against the SharePoint URL stored
                        // "Untitled" per item). Cron uses force=false — alien cleanup must not be web-only.
                        $pre = $this->feeds->deleteAlienParlPressFeedItems($id);
                        if ($pre > 0) {
                            error_log(
                                'Seismo core:parl_press feed ' . $id . ': pre-clean removed ' . $pre
                                . ' non-SharePoint feed_items row(s) before fetch (legacy RSS on this URL).'
                            );
                        }
                        $rows = $this->parlPress->fetchForFeed($feed);
                        $n = $this->feeds->upsertFeedItems($id, $rows);
                        $total += $n;
                        if ($n > 0) {
                            $purged = $this->feeds->deleteAlienParlPressFeedItems($id);
                            if ($purged > 0) {
                                error_log(
                                    'Seismo core:parl_press feed ' . $id . ': removed ' . $purged
                                    . ' non-SharePoint feed_items row(s) after upsert.'
                                );
                            }
                        }
                        $this->feeds->touchFeedSuccess($id);
                    } catch (\Throwable $e) {
                        error_log('Seismo core:parl_press feed ' . $id . ': ' . $e->getMessage());
                        $this->feeds->touchFeedFailure($id, $e->getMessage());
                    }
                }
                if (count($batch) < $page) {
                    break;
                }
                $offset += $page;
            }
            $r = PluginRunResult::ok($total);
        } catch (\Throwable $e) {
            error_log('Seismo core:parl_press: ' . $e->getMessage());
            $r = PluginRunResult::error($e->getMessage());
        }
        $duration = max(0, (int)(microtime(true) * 1000) - $start);
        $this->record(self::ID_PARL_PRESS, $r, $duration);

        return $r;
    }

    private function runScraper(bool $force): PluginRunResult
    {
        if (isSatellite()) {
            $r = PluginRunResult::skipped('Satellite mode — core fetchers do not run here.');
            $this->record(self::ID_SCRAPER, $r, 0);

            return $r;
        }
        if (!$force && $this->isThrottled(self::ID_SCRAPER, self::THROTTLE_SECONDS[self::ID_SCRAPER])) {
            return PluginRunResult::throttleSkipped(
                'Throttled — last successful run is fresher than ' . self::THROTTLE_SECONDS[self::ID_SCRAPER] . 's.'
            );
        }

        $start = (int)(microtime(true) * 1000);
        $total = 0;
        try {
            $offset = 0;
            $page   = 200;
            while (true) {
                $batch = $this->feeds->listFeedsForScraperRefresh($page, $offset);
                if ($batch === []) {
                    break;
                }
                foreach ($batch as $feed) {
                    $id = (int)($feed['id'] ?? 0);
                    $url = trim((string)($feed['url'] ?? ''));
                    if ($id <= 0 || $url === '') {
                        continue;
                    }
                    try {
                        $linkPattern    = trim((string)($feed['scraper_link_pattern'] ?? ''));
                        $dateSel        = trim((string)($feed['scraper_date_selector'] ?? ''));
                        $excludeSels    = trim((string)($feed['scraper_exclude_selectors'] ?? ''));
                        $out            = $this->scraper->fetchScraperFeedItems(
                            $url,
                            $linkPattern,
                            $dateSel,
                            $excludeSels,
                            ScraperFetchService::PRODUCTION_MAX_ARTICLES,
                            true
                        );
                        if ($out['fatal_error'] !== null) {
                            throw new \RuntimeException($out['fatal_error']);
                        }
                        foreach ($out['warnings'] as $w) {
                            error_log('Seismo core:scraper feed ' . $id . ': ' . $w);
                        }
                        $items = $out['items'];
                        $n = $this->feeds->upsertFeedItems($id, $items);
                        $total += $n;
                        $this->feeds->touchFeedSuccess($id);
                    } catch (\Throwable $e) {
                        error_log('Seismo core:scraper feed ' . $id . ': ' . $e->getMessage());
                        $this->feeds->touchFeedFailure($id, $e->getMessage());
                    }
                }
                if (count($batch) < $page) {
                    break;
                }
                $offset += $page;
            }
            $r = PluginRunResult::ok($total);
        } catch (\Throwable $e) {
            error_log('Seismo core:scraper: ' . $e->getMessage());
            $r = PluginRunResult::error($e->getMessage());
        }
        $duration = max(0, (int)(microtime(true) * 1000) - $start);
        $this->record(self::ID_SCRAPER, $r, $duration);

        return $r;
    }

    private function runMail(bool $force): PluginRunResult
    {
        if (isSatellite()) {
            $r = PluginRunResult::skipped('Satellite mode — core fetchers do not run here.');
            $this->record(self::ID_MAIL, $r, 0);

            return $r;
        }
        if (!$force && $this->isThrottled(self::ID_MAIL, self::THROTTLE_SECONDS[self::ID_MAIL])) {
            return PluginRunResult::throttleSkipped(
                'Throttled — last successful run is fresher than ' . self::THROTTLE_SECONDS[self::ID_MAIL] . 's.'
            );
        }

        $start = (int)(microtime(true) * 1000);
        try {
            if (!extension_loaded('imap')) {
                $r = PluginRunResult::skipped(
                    'PHP imap extension is not enabled — install ext-imap on the server for core:mail.',
                    $force
                );
            } elseif (!$this->mailImapConfigured()) {
                $r = PluginRunResult::skipped(
                    'IMAP not configured — set system_config keys mail_imap_mailbox (or mail_imap_host), mail_imap_username, mail_imap_password.',
                    $force
                );
            } else {
                $cfg  = $this->loadMailImapConfig();
                $rows = $this->imapMail->fetch($cfg);
                $n    = $this->emailIngest->upsertImapBatch($rows);
                if ($n > 0 && $rows !== []) {
                    try {
                        $this->imapMail->markSeen($cfg, array_map(
                            static fn (array $row): int => (int)($row['imap_uid'] ?? 0),
                            $rows
                        ));
                    } catch (\Throwable $e) {
                        error_log('Seismo core:mail mark seen: ' . $e->getMessage());
                    }
                }
                $r = PluginRunResult::ok($n);
            }
        } catch (\Throwable $e) {
            error_log('Seismo core:mail: ' . $e->getMessage());
            $r = PluginRunResult::error($e->getMessage());
        }
        $duration = max(0, (int)(microtime(true) * 1000) - $start);
        $this->record(self::ID_MAIL, $r, $duration);

        return $r;
    }

    private function mailImapConfigured(): bool
    {
        $c = $this->loadMailImapConfig();
        $user = trim((string)($c['mail_imap_username'] ?? ''));
        if ($user === '') {
            return false;
        }
        $mb = trim((string)($c['mail_imap_mailbox'] ?? ''));
        $host = trim((string)($c['mail_imap_host'] ?? ''));

        return $mb !== '' || $host !== '';
    }

    /**
     * @return array<string, string|null>
     */
    private function loadMailImapConfig(): array
    {
        $keys = [
            'mail_imap_mailbox',
            'mail_imap_username',
            'mail_imap_password',
            'mail_imap_host',
            'mail_imap_port',
            'mail_imap_flags',
            'mail_imap_folder',
            'mail_max_messages',
            'mail_search_criteria',
            'mail_mark_seen',
        ];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->magnituConfig->get($k);
        }

        return $out;
    }

    private function isThrottled(string $coreId, int $minSeconds): bool
    {
        if ($minSeconds <= 0) {
            return false;
        }
        $last = $this->runLog->lastSuccessfulRunAt($coreId);
        if ($last === null) {
            return false;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return ($now->getTimestamp() - $last->getTimestamp()) < $minSeconds;
    }

    private function record(string $id, PluginRunResult $result, int $durationMs): void
    {
        if (!$result->persistToPluginRunLog) {
            return;
        }
        try {
            $this->runLog->record($id, $result, $durationMs);
        } catch (\Throwable $e) {
            error_log('Seismo core_run_log write failed: ' . $e->getMessage());
        }
    }
}
