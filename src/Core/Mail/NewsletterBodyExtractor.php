<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

use fivefilters\Readability\Configuration;
use fivefilters\Readability\Readability;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * Derive readable plain text from newsletter HTML (Slice 11).
 *
 * Replaces naive {@see \Seismo\Core\Fetcher\EmailHtmlPlainText} strip_tags behaviour.
 */
final class NewsletterBodyExtractor
{
    private static ?HtmlConverter $markdown = null;

    public static function fromHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $articleHtml = self::extractArticleHtml($html);
        if ($articleHtml === '') {
            return self::postProcess(self::fallbackPlain($html));
        }

        return self::postProcess(self::htmlToPlain($articleHtml));
    }

    private static function postProcess(string $text): string
    {
        if ($text === '') {
            return '';
        }

        return EmailListingBoilerplateStripper::strip($text, null);
    }

    private static function extractArticleHtml(string $html): string
    {
        try {
            $config = new Configuration([
                'fixRelativeURLs' => false,
                'originalURL'     => 'https://localhost/',
                'charThreshold'   => 80,
            ]);
            $readability = new Readability($config);
            $readability->parse($html);
            $content = $readability->getContent();
            if (is_string($content) && trim($content) !== '') {
                return $content;
            }
        } catch (\Throwable $e) {
            error_log('Seismo NewsletterBodyExtractor: ' . $e->getMessage());
        }

        return '';
    }

    private static function htmlToPlain(string $html): string
    {
        try {
            $converter = self::markdownConverter();
            $md        = trim($converter->convert($html));
            if ($md !== '') {
                $md = preg_replace("/\n{3,}/", "\n\n", $md) ?? $md;

                return trim($md);
            }
        } catch (\Throwable $e) {
            error_log('Seismo NewsletterBodyExtractor markdown: ' . $e->getMessage());
        }

        return self::fallbackPlain($html);
    }

    private static function fallbackPlain(string $html): string
    {
        $clean = preg_replace('/<(style|script)\b[^>]*>.*<\/\\1>/is', '', $html) ?? '';
        $text  = strip_tags($clean);

        return trim(preg_replace('/\s+/', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private static function markdownConverter(): HtmlConverter
    {
        if (self::$markdown === null) {
            $c = new HtmlConverter([
                'strip_tags'      => true,
                'remove_nodes'    => 'head style script',
                'hard_break'      => true,
                'header_style'    => 'atx',
            ]);
            self::$markdown = $c;
        }

        return self::$markdown;
    }
}
