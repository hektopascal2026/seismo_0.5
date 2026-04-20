<?php

declare(strict_types=1);

namespace Seismo\Core\Fetcher;

use DateTimeImmutable;
use DateTimeZone;
use Seismo\Service\Http\BaseClient;

/**
 * Swiss Parliament press releases (parlament.ch SharePoint REST list).
 * Returns rows for {@see \Seismo\Repository\FeedItemRepository::upsertFeedItems()}.
 *
 * Configuration comes from the parent `feeds` row:
 * - `url` — SharePoint list items endpoint (GET), e.g. …/lists/getByTitle('Pages')/items
 * - `description` — optional JSON:
 *   - `lookback_days`, `limit`, `language` — same as always.
 *   - `odata_title_substring` — when set, adds `and substringof('…',Title)` (SharePoint OData2).
 *     Example: `"sda-"` limits rows to SDA agency slugs (`sda-apk-n-…`, `mm-sda-…`) in the same Pages list.
 *   - `guid_prefix` — stable id prefix for `feed_items.guid` (default `parl_mm`). Use `parl_sda` for SDA-only feeds
 *     so rows stay distinct from Medienmitteilungen and survive alien-row cleanup. When `odata_title_substring` is
 *     set and `guid_prefix` is **omitted** from JSON, it defaults to **`parl_sda`** (filtered feeds are almost always
 *     a second logical source; forgetting the key used to store `parl_mm:` guids and broke the dashboard pill).
 */
final class ParlPressFetchService
{
    private const DEFAULT_LOOKBACK = 90;

    private const DEFAULT_LIMIT = 50;

    /** @var list<string> */
    private const LANGUAGES = ['de', 'fr', 'it', 'en', 'rm'];

    public function __construct(
        private readonly BaseClient $http = new BaseClient(),
    ) {
    }

    /**
     * @param array<string, mixed> $feedRow Full `feeds` row (must have `source_type` parl_press).
     *
     * @return list<array<string, mixed>>
     */
    public function fetchForFeed(array $feedRow): array
    {
        if (($feedRow['source_type'] ?? '') !== 'parl_press') {
            return [];
        }

        $apiBase = trim((string)($feedRow['url'] ?? ''));
        if ($apiBase === '') {
            throw new \RuntimeException('Parl press feed has an empty API URL.');
        }

        $opts = $this->parseOptions((string)($feedRow['description'] ?? ''));
        $lookback = max(1, min(365, (int)($opts['lookback_days'] ?? self::DEFAULT_LOOKBACK)));
        $limit    = max(1, min(200, (int)($opts['limit'] ?? self::DEFAULT_LIMIT)));
        $lang     = $this->normaliseLanguage((string)($opts['language'] ?? 'de'));
        $titleNeedle = trim((string)($opts['odata_title_substring'] ?? ''));
        $guidPrefixRaw = array_key_exists('guid_prefix', $opts) ? $opts['guid_prefix'] : null;
        if ($guidPrefixRaw === null && $titleNeedle !== '') {
            $guidPrefix = 'parl_sda';
        } else {
            $guidPrefix = $this->normaliseGuidPrefix((string)($guidPrefixRaw ?? 'parl_mm'));
        }

        $sinceUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . $lookback . ' days')
            ->format('Y-m-d\TH:i:s\Z');

        $filter = "Created ge datetime'{$sinceUtc}'";
        if ($titleNeedle !== '') {
            $escaped = str_replace("'", "''", $titleNeedle);
            // SharePoint OData: use `substringof('x',Title)` — `eq true` is rejected when combined with `and`.
            $filter .= " and substringof('{$escaped}',Title)";
        }

        $langField    = 'Title_' . $lang;
        $contentField = 'Content_' . $lang;
        // Request every localized title column so we can fall back when the
        // preferred language is empty or still the SharePoint placeholder
        // "Untitled" (0.4 stored Title_* in lex_items.title — same upstream).
        $titleCols = ['Title'];
        foreach (self::LANGUAGES as $l) {
            $titleCols[] = 'Title_' . $l;
        }
        $select  = implode(',', $titleCols) . ',' . $contentField . ',FileRef,Created,ArticleStartDate,ContentType/Name';
        $orderBy = 'Created desc';

        $url = $apiBase
            . '?$top=' . $limit
            . '&$orderby=' . rawurlencode($orderBy)
            . '&$filter=' . rawurlencode($filter)
            . '&$select=' . rawurlencode($select)
            . '&$expand=ContentType';

        $response = $this->http->get($url, [
            'Accept' => 'application/json;odata=verbose',
        ]);

        if ($response->status < 200 || $response->status >= 300) {
            throw new \RuntimeException(
                'parlament.ch API HTTP ' . $response->status . ': ' . mb_substr($response->body, 0, 200)
            );
        }

        $data = json_decode($response->body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('parlament.ch API returned invalid JSON.');
        }

        /** @var list<array<string, mixed>> $items */
        $items = $data['value'] ?? $data['d']['results'] ?? [];
        if ($items === []) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            $slug = $this->resolveParlPressSlug($item);
            if ($slug === '') {
                continue;
            }

            $title = $this->resolveParlPressTitle($item, $lang, $slug);

            $fileRef = trim((string)($item['FileRef'] ?? ''));
            $pageUrl = 'https://www.parlament.ch' . $fileRef;
            if ($fileRef === '' || !$this->isNavigableHttpUrl($pageUrl)) {
                continue;
            }

            $rawDate = $item['ArticleStartDate'] ?? $item['Created'] ?? null;
            $pub     = null;
            if (is_string($rawDate) && $rawDate !== '') {
                $ts = strtotime($rawDate);
                if ($ts !== false) {
                    $pub = (new DateTimeImmutable('@' . $ts, new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
                }
            }

            $contentType = $item['ContentType']['Name'] ?? 'Press Release';
            $contentType = is_string($contentType) ? $contentType : 'Press Release';

            $rawContent = (string)($item[$contentField] ?? '');
            $plain      = trim(strip_tags($rawContent));

            $guid = $guidPrefix . ':' . $slug;
            $guid = mb_substr($guid, 0, 500);

            $commission = self::commissionFromSlug($slug);

            if (self::isMeaninglessParlPressTitle($title)) {
                continue;
            }

            $out[] = [
                'guid'             => $guid,
                'title'            => mb_substr($title, 0, 500),
                'link'             => mb_substr($pageUrl, 0, 500),
                'description'      => $plain,
                'content'          => $plain !== '' ? $plain : $title,
                // Human-facing second pill on the dashboard (0.4: lex document_type).
                'author'           => $commission !== '' ? $commission : (string)$contentType,
                'published_date'   => $pub,
                'content_hash'     => '',
            ];
        }

        return $out;
    }

    private function normaliseGuidPrefix(string $raw): string
    {
        $raw = strtolower(trim($raw));
        // Keep in sync with {@see \Seismo\Repository\FeedItemRepository::deleteAlienParlPressFeedItems()}.
        return in_array($raw, ['parl_mm', 'parl_sda'], true) ? $raw : 'parl_mm';
    }

    /**
     * @return array<string, mixed>
     */
    private function parseOptions(string $description): array
    {
        $description = trim($description);
        if ($description === '') {
            return [];
        }
        $decoded = json_decode($description, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normaliseLanguage(string $raw): string
    {
        $l = strtolower(trim($raw));

        return in_array($l, self::LANGUAGES, true) ? $l : 'de';
    }

    /**
     * Internal URL slug: list column {@see Title}, or basename of {@see FileRef} when Title is empty / "Untitled".
     *
     * @param array<string, mixed> $item SharePoint list row (verbose JSON).
     */
    private function resolveParlPressSlug(array $item): string
    {
        $fromTitle = trim((string)($item['Title'] ?? ''));
        if ($fromTitle !== '' && !self::isMeaninglessParlPressTitle($fromTitle)) {
            return $fromTitle;
        }
        $ref = trim((string)($item['FileRef'] ?? ''));
        if ($ref === '') {
            return $fromTitle;
        }
        $base = basename($ref);
        if ($base === '' || $base === '.' || $base === '..') {
            return $fromTitle;
        }
        $slug = preg_replace('/\.aspx$/i', '', $base) ?? $base;
        $slug = trim((string)$slug);

        return $slug !== '' ? $slug : $fromTitle;
    }

    /**
     * @param array<string, mixed> $item SharePoint list row (verbose JSON).
     */
    private function resolveParlPressTitle(array $item, string $preferredLang, string $slug): string
    {
        $try = [];
        foreach (array_merge([$preferredLang], self::LANGUAGES) as $l) {
            $k = 'Title_' . $l;
            if (!in_array($k, $try, true)) {
                $try[] = $k;
            }
        }
        foreach ($try as $field) {
            $t = trim((string)($item[$field] ?? ''));
            if ($t === '' || self::isMeaninglessParlPressTitle($t)) {
                continue;
            }

            return $t;
        }
        $slugTrim = trim($slug);

        return $slugTrim !== '' && !self::isMeaninglessParlPressTitle($slugTrim) ? $slugTrim : $slug;
    }

    private static function isMeaninglessParlPressTitle(string $t): bool
    {
        $n = mb_strtolower(trim($t));

        return $n === 'untitled' || $n === '(untitled)' || $n === '(no title)';
    }

    /**
     * Commission abbreviation from press slug (ported from 0.4
     * {@see parseParlMmCommission} in lex_jus.php).
     */
    public static function commissionFromSlug(string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }
        if (preg_match('/^mm-([a-z]+)-([nsr])-\d{4}/i', $slug, $m)) {
            return strtoupper($m[1]) . '-' . strtoupper($m[2]);
        }
        if (preg_match('/^mm-([a-z]+)-\d{4}/i', $slug, $m)) {
            return strtoupper($m[1]);
        }
        if (preg_match('/^sda-([a-z]+)-([nsr])-\d{4}/i', $slug, $m)) {
            return strtoupper($m[1]) . '-' . strtoupper($m[2]);
        }
        if (preg_match('/^sda-([a-z]+)-\d{4}/i', $slug, $m)) {
            return strtoupper($m[1]);
        }
        if (str_contains($slug, '-sda-') || str_starts_with(strtolower($slug), 'mm-sda')) {
            return 'SDA';
        }
        if (str_starts_with($slug, 'info-')) {
            return 'Info';
        }

        return 'Medienmitteilung';
    }

    private function isNavigableHttpUrl(string $url): bool
    {
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
