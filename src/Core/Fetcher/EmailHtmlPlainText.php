<?php

declare(strict_types=1);

namespace Seismo\Core\Fetcher;

/**
 * Derive a single-line-ish plain string from HTML mail bodies.
 * Same rules as 0.4 `fetcher/mail/fetch_mail.php` (`parse_message`).
 */
final class EmailHtmlPlainText
{
    public static function fromHtml(string $html): string
    {
        $clean = preg_replace('/<(style|script)\b[^>]*>.*<\/\\1>/is', '', $html) ?? '';
        $text  = strip_tags($clean);

        return trim(preg_replace('/\s+/', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
