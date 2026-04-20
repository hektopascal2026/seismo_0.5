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
 * - `url` — SharePoint list items endpoint (GET).
 * - `description` — optional JSON: `{"lookback_days":90,"limit":50,"language":"de"}`.
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

        $sinceUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . $lookback . ' days')
            ->format('Y-m-d\TH:i:s\Z');

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
        $filter  = "Created ge datetime'{$sinceUtc}'";
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
            $slug = trim((string)($item['Title'] ?? ''));
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

            $guid = 'parl_mm:' . $slug;
            $guid = mb_substr($guid, 0, 500);

            $commission = self::commissionFromSlug($slug);

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

    /**
     * @return array{lookback_days?: int, limit?: int, language?: string}
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
