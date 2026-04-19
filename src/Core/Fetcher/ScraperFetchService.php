<?php

declare(strict_types=1);

namespace Seismo\Core\Fetcher;

use DateTimeImmutable;
use DateTimeZone;
use Seismo\Service\Http\BaseClient;

/**
 * Minimal HTML page scrape → one feed_item-shaped row per configured feed URL.
 */
final class ScraperFetchService
{
    public function __construct(private BaseClient $http = new BaseClient())
    {
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
}
