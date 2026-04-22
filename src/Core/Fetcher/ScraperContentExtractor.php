<?php

declare(strict_types=1);

namespace Seismo\Core\Fetcher;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;

/**
 * Readability-lite + date extraction — ported from 0.4 seismo_scraper.php behaviour
 * (see project docs / consolidation notes). Used by {@see ScraperFetchService} preview
 * only until production ingest is switched to the same pipeline.
 */
final class ScraperContentExtractor
{
    /**
     * @return array{title: string, content: string}
     */
    public static function extractReadableContent(string $html): array
    {
        $html = self::normaliseEncodingPrefix($html);
        $prev  = libxml_use_internal_errors(true);
        $dom   = new DOMDocument();
        $loaded = $dom->loadHTML($html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            return ['title' => '', 'content' => ''];
        }

        $title = self::titleFromDocument($dom);
        self::removeNoiseElements($dom);

        $best = '';
        $bestLen = 0;
        $tags  = ['article', 'main', 'div', 'section'];
        foreach ($tags as $tag) {
            $els = $dom->getElementsByTagName($tag);
            for ($i = 0; $i < $els->length; $i++) {
                $el = $els->item($i);
                if (!($el instanceof DOMElement)) {
                    continue;
                }
                $t = self::textContent($el);
                $len = mb_strlen($t, 'UTF-8');
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $best = $t;
                }
                if ($len > 200 && $best === $t) {
                    // Keep scanning for longest; 0.4 “stop early” = optional perf only.
                }
            }
        }

        if ($bestLen < 50) {
            $bodies = $dom->getElementsByTagName('body');
            if ($bodies->length > 0) {
                $body = $bodies->item(0);
                if ($body instanceof DOMElement) {
                    $best = self::textContent($body);
                }
            }
        }

        $content = self::normaliseTextWhitespace($best);

        return [
            'title'   => $title,
            'content' => $content,
        ];
    }

    /**
     * First matching value as UTC `Y-m-d H:i:s`, or null.
     * Selector: limited CSS, raw XPath (starts with `/`), or `//...`.
     */
    public static function extractPublishedDate(string $html, string $dateSelector): ?string
    {
        $dateSelector = trim($dateSelector);
        if ($dateSelector === '') {
            return null;
        }

        $html = self::normaliseEncodingPrefix($html);
        $prev  = libxml_use_internal_errors(true);
        $dom   = new DOMDocument();
        $loaded = $dom->loadHTML($html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            return null;
        }

        $xpQuery = self::dateSelectorToXPath($dateSelector);
        if ($xpQuery === null) {
            return null;
        }

        $xp = new DOMXPath($dom);
        $list = @$xp->query($xpQuery);
        if ($list === false || !($list instanceof DOMNodeList) || $list->length === 0) {
            return self::tryGermanDateStrings($html);
        }

        $node = $list->item(0);
        if ($node === null) {
            return self::tryGermanDateStrings($html);
        }
        if ($node instanceof DOMElement) {
            $attrOrder = ['datetime', 'content', 'data-date', 'data-datetime', 'date'];
            foreach ($attrOrder as $attr) {
                if ($node->hasAttribute($attr)) {
                    $p = self::strtotimeToDbUtc($node->getAttribute($attr));
                    if ($p !== null) {
                        return $p;
                    }
                }
            }
        }
        $raw = $node->textContent ?? '';
        $p   = self::strtotimeToDbUtc(trim($raw));
        if ($p !== null) {
            return $p;
        }

        return self::tryGermanDateStrings($html);
    }

    private static function tryGermanDateStrings(string $html): ?string
    {
        if (preg_match_all('#\b(\d{1,2}\.\d{1,2}\.\d{2,4})\b#u', $html, $m)) {
            foreach ($m[1] as $cand) {
                $p = self::strtotimeToDbUtc(str_replace('.', '-', $cand));
                if ($p !== null) {
                    return $p;
                }
            }
        }

        return null;
    }

    private static function strtotimeToDbUtc(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        $dt = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('UTC'));

        return $dt->format('Y-m-d H:i:s');
    }

    private static function dateSelectorToXPath(string $s): ?string
    {
        if (str_starts_with($s, '/') || str_starts_with($s, '(')) {
            return $s;
        }
        if (str_starts_with($s, 'meta[')) {
            if (preg_match('/^meta\[property="([^"]+)"\]$/i', $s, $m)) {
                return '//meta[@property=' . self::xpathStringLiteral($m[1]) . ']';
            }
        }
        if (preg_match('/^#([A-Za-z0-9_\-]+)$/', $s, $m)) {
            return '//*[@id=' . self::xpathStringLiteral($m[1]) . ']';
        }
        if (preg_match('/^\.([A-Za-z0-9_\-]+)$/', $s, $m)) {
            $c = $m[1];

            return '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $c . ' ")]';
        }
        if (preg_match('/^([a-z0-9]+)\.([A-Za-z0-9_\-]+)$/i', $s, $m)) {
            $tag = $m[1];
            $c   = $m[2];

            return '//' . $tag . '[contains(concat(" ", normalize-space(@class), " "), " ' . $c . ' ")]';
        }
        if (preg_match('/^[a-z0-9]+$/i', $s)) {
            return '//' . $s;
        }
        if (preg_match('/^([a-z0-9]+)\[([a-z0-9\-]+)(?:="([^"]*)")?\]$/i', $s, $m)) {
            $tag = $m[1];
            $a   = $m[2];
            if (isset($m[3]) && $m[3] !== '') {
                return '//' . $tag . '[@' . $a . '=' . self::xpathStringLiteral($m[3]) . ']';
            }

            return '//' . $tag . '[@' . $a . ']';
        }

        return null;
    }

    private static function xpathStringLiteral(string $s): string
    {
        if (!str_contains($s, "'")) {
            return "'" . $s . "'";
        }
        if (!str_contains($s, '"')) {
            return '"' . $s . '"';
        }
        $parts = explode("'", $s);
        $bits  = [];
        foreach ($parts as $i => $p) {
            if ($i > 0) {
                $bits[] = '"\'"';
            }
            if ($p !== '' || $i === 0) {
                $bits[] = "'" . $p . "'";
            }
        }

        return 'concat(' . implode(', ', $bits) . ')';
    }

    private static function normaliseEncodingPrefix(string $html): string
    {
        if (str_starts_with($html, '<?xml')) {
            return $html;
        }

        return '<?xml encoding="UTF-8">' . $html;
    }

    private static function titleFromDocument(DOMDocument $dom): string
    {
        $titles = $dom->getElementsByTagName('title');
        if ($titles->length > 0) {
            $t = trim($titles->item(0)->textContent ?? '');

            return html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return '';
    }

    private static function removeNoiseElements(DOMDocument $dom): void
    {
        $removeTags = ['script', 'style', 'nav', 'header', 'footer', 'aside', 'noscript', 'iframe'];
        foreach ($removeTags as $tag) {
            $els = $dom->getElementsByTagName($tag);
            $toRemove = [];
            for ($i = 0; $i < $els->length; $i++) {
                $n = $els->item($i);
                if ($n?->parentNode !== null) {
                    $toRemove[] = $n;
                }
            }
            foreach ($toRemove as $n) {
                $n->parentNode?->removeChild($n);
            }
        }
    }

    private static function textContent(DOMElement $el): string
    {
        $t = $el->textContent ?? '';
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return self::normaliseTextWhitespace($t);
    }

    private static function normaliseTextWhitespace(string $text): string
    {
        $text = preg_replace("/[\h\x{00A0}]+/u", ' ', $text) ?? $text;
        $text = preg_replace('/\R{3,}/u', "\n\n", $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
