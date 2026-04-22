<?php

declare(strict_types=1);

namespace Seismo\Core\Mail;

/**
 * Removes the fixed “News Service Bund … | date / …, place, date -” listing lede
 * from plain-text e-mail bodies (Admin.ch press digests). Used at ingest, recipe
 * scoring, Magnitu export, and dashboard display when a subscription has
 * {@see strip_listing_boilerplate} enabled.
 */
final class EmailListingBoilerplateStripper
{
    public static function strip(string $body, ?string $subject = null): string
    {
        $body = trim($body);
        if ($body === '') {
            return $body;
        }
        $body = (string) preg_replace(
            '/^News Service Bund\s+www\.news\.admin\.ch\s+[^\|]+\s*\|\s*\d{1,2}\.\d{1,2}\.\d{1,4}\s+/u',
            '',
            $body,
            1
        );
        $body = trim($body);
        if ($body === '') {
            return $body;
        }
        $sub = trim((string) $subject);
        if ($sub !== '' && $sub !== '(No subject)' && str_starts_with($body, $sub)) {
            $body = trim(mb_substr($body, mb_strlen($sub)));
        }
        if ($body !== '' && str_contains($body, ',')) {
            $body = (string) preg_replace(
                '/^(.+),\s*\d{1,2}\.\d{1,2}\.\d{1,4}\s*-\s*/us',
                '',
                $body,
                1
            );
        }

        return trim($body);
    }
}
