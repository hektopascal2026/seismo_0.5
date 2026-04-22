<?php

declare(strict_types=1);

namespace Seismo\Core\Fetcher;

use DateTimeImmutable;
use DateTimeZone;
use Seismo\Service\Http\BaseClient;

/**
 * Minimal HTML page scrape → one feed_item-shaped row per configured feed URL.
 *
 * {@see self::preview()} is the dry-run / spec: 0.4-style substring link_pattern,
 * same-host only, fragment dedupe, readability-lite body + optional date_selector,
 * guid = article URL, md5(content_hash). Production CoreRunner still uses
 * {@see self::scrapePage()} (legacy strip) until aligned.
 */
final class ScraperFetchService
{
    /** Max successful detail items returned by {@see self::preview()} when a link pattern is set. */
    public const PREVIEW_MAX_ITEMS = 5;

    /** Upper bound on anchor hrefs to scan (first wins in document order) after pattern filter. */
    private const PREVIEW_MAX_LINKS_SCAN = 50;

    public function __construct(private BaseClient $http = new BaseClient())
    {
    }

    /**
     * Stateless preview: fetch listing (or single page when $linkPattern is empty),
     * optionally match links with a substring in the resolved URL (0.4 semantics), fetch up
     * to {@see self::PREVIEW_MAX_ITEMS} targets using readability + date_selector. Does
     * not touch the database.
     *
     * @return array{
     *     ok: bool,
     *     error?: string,
     *     warnings: list<string>,
     *     items: list<array<string, mixed>>
     * }
     */
    public function preview(
        string $pageUrl,
        string $linkPattern,
        int $maxItems = self::PREVIEW_MAX_ITEMS,
        string $dateSelector = ''
    ): array {
        $pageUrl = trim($pageUrl);
        if ($pageUrl === '' || !$this->isNavigableHttpUrl($pageUrl)) {
            return ['ok' => false, 'error' => 'A valid http(s) URL is required.', 'warnings' => [], 'items' => []];
        }

        $dateSel = trim($dateSelector);
        $dsOpt   = $dateSel === '' ? null : $dateSel;

        $linkPattern = trim($linkPattern);
        if ($linkPattern === '') {
            try {
                $rows = $this->scrapeArticleForPreview($pageUrl, $dsOpt);

                return [
                    'ok'         => true,
                    'warnings'   => [],
                    'items'      => $rows,
                ];
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => $e->getMessage(), 'warnings' => [], 'items' => []];
            }
        }

        $warnings = [];
        try {
            $html = $this->fetchHtmlBody($pageUrl);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'warnings' => [], 'items' => []];
        }

        $candidates = $this->collectMatchingLinkUrls($pageUrl, $html, $linkPattern, self::PREVIEW_MAX_LINKS_SCAN);
        if ($candidates === []) {
            return [
                'ok'         => false,
                'error'      => 'No same-host links on the page contain the link pattern (substring, like 0.4).',
                'warnings'   => $warnings,
                'items'      => [],
            ];
        }

        $items = [];
        $maxItems = max(1, min($maxItems, self::PREVIEW_MAX_ITEMS));
        foreach ($candidates as $targetUrl) {
            if (count($items) >= $maxItems) {
                break;
            }
            try {
                $chunk = $this->scrapeArticleForPreview($targetUrl, $dsOpt);
                if ($chunk !== []) {
                    $items[] = $chunk[0];
                }
            } catch (\Throwable $e) {
                $warnings[] = 'Failed to fetch ' . $targetUrl . ': ' . $e->getMessage();
            }
        }

        if ($items === []) {
            return [
                'ok'         => false,
                'error'      => 'Every matched link failed to load. See warnings.',
                'warnings'   => $warnings,
                'items'      => [],
            ];
        }

        return ['ok' => true, 'warnings' => $warnings, 'items' => $items];
    }

    /**
     * Preview / future-ingest spec: 0.4-style readable body, optional date, guid = URL, md5 hash.
     * Core cron still uses {@see self::scrapePage()} until wired.
     *
     * @return list<array<string, mixed>>
     */
    private function scrapeArticleForPreview(string $pageUrl, ?string $dateSelector): array
    {
        $html = $this->fetchHtmlBody($pageUrl);
        $read = ScraperContentExtractor::extractReadableContent($html);
        $content = trim($read['content'] ?? '');
        if ($content === '') {
            throw new \RuntimeException('No readable text extracted for ' . $pageUrl);
        }
        if (mb_strlen($content) > 50000) {
            $content = mb_substr($content, 0, 50000);
        }
        $title = trim($read['title'] ?? '');
        if ($title === '') {
            $title = $pageUrl;
        }
        $published = null;
        if ($dateSelector !== null && $dateSelector !== '') {
            $published = ScraperContentExtractor::extractPublishedDate($html, $dateSelector);
        }
        if ($published === null) {
            $published = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        }
        $guid = mb_substr($pageUrl, 0, 500);

        return [[
            'guid'             => $guid,
            'title'            => mb_substr($title, 0, 500),
            'link'             => mb_substr($pageUrl, 0, 500),
            'description'      => mb_substr($content, 0, 2000),
            'content'          => $content,
            'author'           => '',
            'published_date'   => $published,
            'content_hash'     => md5($content),
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function scrapePage(string $pageUrl): array
    {
        $pageUrl = trim($pageUrl);
        if ($pageUrl === '' || !$this->isNavigableHttpUrl($pageUrl)) {
            return [];
        }

        $res = $this->http->get($pageUrl);
        if ($res->status < 200 || $res->status >= 400) {
            throw new \RuntimeException('HTTP ' . $res->status . ' fetching ' . $pageUrl);
        }
        $html = $res->body;
        if ($html === '') {
            throw new \RuntimeException('Empty body for ' . $pageUrl);
        }

        $title = $this->extractTitle($html);
        if ($title === '') {
            $title = $pageUrl;
        }
        $text = $this->stripToText($html);
        if (mb_strlen($text) > 50000) {
            $text = mb_substr($text, 0, 50000);
        }

        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $guid = substr(sha1($pageUrl . "\0" . $title), 0, 32);

        return [[
            'guid'           => $guid,
            'title'          => mb_substr($title, 0, 500),
            'link'           => mb_substr($pageUrl, 0, 500),
            'description'    => mb_substr($text, 0, 2000),
            'content'        => $text,
            'author'         => '',
            'published_date' => $now,
            'content_hash'   => substr(sha1($text), 0, 32),
        ]];
    }

    private function extractTitle(string $html): string
    {
        if (preg_match('#<title[^>]*>([^<]+)</title>#i', $html, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return '';
    }

    private function stripToText(string $html): string
    {
        $t = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
        $t = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $t) ?? $t;
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        return trim($t);
    }

    private function isNavigableHttpUrl(string $url): bool
    {
        $u = trim($url);
        if ($u === '' || $u === '#') {
            return false;
        }

        return (bool)preg_match('#^https?://#i', $u);
    }

    private function fetchHtmlBody(string $pageUrl): string
    {
        $res = $this->http->get($pageUrl);
        if ($res->status < 200 || $res->status >= 400) {
            throw new \RuntimeException('HTTP ' . $res->status . ' fetching listing ' . $pageUrl);
        }
        if ($res->body === '') {
            throw new \RuntimeException('Empty body for listing ' . $pageUrl);
        }

        return $res->body;
    }

    /**
     * @return list<string>
     */
    private function collectMatchingLinkUrls(string $listingUrl, string $html, string $linkPattern, int $maxScan): array
    {
        $maxScan = max(1, min($maxScan, 200));
        $libxmlPrev = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($libxmlPrev);
        if (!$loaded) {
            return [];
        }

        $listingParts = parse_url($listingUrl);
        $listingHost  = strtolower((string)($listingParts['host'] ?? ''));

        $links = $dom->getElementsByTagName('a');
        $normListing = $this->normalizeUrlForCompare($this->stripFragment($listingUrl));
        $seen = [];
        $out = [];
        for ($i = 0; $i < $links->length; $i++) {
            if (count($out) >= $maxScan) {
                break;
            }
            $el = $links->item($i);
            if (!($el instanceof \DOMElement)) {
                continue;
            }
            $rawHref = $el->getAttribute('href');
            $absolute = $this->resolveAgainstBase($listingUrl, $rawHref);
            if ($absolute === '' || !$this->isNavigableHttpUrl($absolute)) {
                continue;
            }
            $targetParts = parse_url($absolute);
            $targetHost  = strtolower((string)($targetParts['host'] ?? ''));
            if ($listingHost === '' || $targetHost !== $listingHost) {
                continue;
            }
            $canon = $this->stripFragment($absolute);
            if ($this->normalizeUrlForCompare($canon) === $normListing) {
                continue;
            }
            if (strpos($absolute, $linkPattern) === false) {
                continue;
            }
            $dedupeKey = $this->normalizeUrlForCompare($canon);
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;
            $out[] = $absolute;
        }

        return $out;
    }

    /**
     * Strip #fragment for deduplication (0.4).
     */
    private function stripFragment(string $url): string
    {
        $p = strpos($url, '#');
        if ($p === false) {
            return $url;
        }

        return substr($url, 0, $p);
    }

    private function normalizeUrlForCompare(string $url): string
    {
        $u = rtrim($url, '/');

        return strtolower($u);
    }

    /**
     * RFC 3986–style resolution (good enough for preview).
     */
    private function resolveAgainstBase(string $base, string $ref): string
    {
        $ref = str_replace(["\0", "\r", "\n"], '', trim($ref));
        if ($ref === '' || str_starts_with($ref, '#') || str_starts_with(strtolower($ref), 'javascript:')
            || str_starts_with(strtolower($ref), 'mailto:') || str_starts_with(strtolower($ref), 'tel:')) {
            return '';
        }
        if (preg_match('#^https?://#i', $ref)) {
            return $ref;
        }
        $b = parse_url($base);
        if ($b === false || ($b['host'] ?? '') === '') {
            return '';
        }
        $scheme = $b['scheme'] ?? 'https';
        $user = $b['user'] ?? '';
        $pass = $b['pass'] ?? '';
        $auth = $user !== '' ? $user . ($pass !== '' ? ':' . $pass : '') . '@' : '';
        $host = $b['host'];
        $port = isset($b['port']) ? ':' . $b['port'] : '';
        if (str_starts_with($ref, '//')) {
            return $scheme . ':' . $ref;
        }
        $path = (string)($b['path'] ?? '');
        if ($path === '') {
            $path = '/';
        }
        if (str_starts_with($ref, '/')) {
            $newPath = $ref;
        } elseif (str_starts_with($ref, '?')) {
            return $scheme . '://' . $auth . $host . $port . $path . $ref;
        } else {
            $dir = str_contains($path, '/') ? substr($path, 0, (int)strrpos($path, '/') + 1) : '/';
            $newPath = $dir . $ref;
        }
        $newPath = $this->collapsePathSegments($newPath);
        if (!str_starts_with($newPath, '/')) {
            $newPath = '/' . $newPath;
        }

        return $scheme . '://' . $auth . $host . $port . $newPath;
    }

    private function collapsePathSegments(string $path): string
    {
        $isAbs = str_starts_with($path, '/');
        $raw = $isAbs ? substr($path, 1) : $path;
        $parts = $raw === '' ? [] : explode('/', $raw);
        $stack = [];
        foreach ($parts as $p) {
            if ($p === '' || $p === '.') {
                continue;
            }
            if ($p === '..') {
                if ($stack !== []) {
                    array_pop($stack);
                }
                continue;
            }
            $stack[] = $p;
        }
        $out = ($isAbs ? '/' : '') . implode('/', $stack);

        return $out === '' && $isAbs ? '/' : $out;
    }
}
