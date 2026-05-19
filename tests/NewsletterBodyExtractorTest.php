<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Core\Mail\NewsletterBodyExtractor;

final class NewsletterBodyExtractorTest extends TestCase
{
    public function testEcowasFixtureExtractsHeadlineNotOnlyBoilerplate(): void
    {
        $html = file_get_contents(__DIR__ . '/fixtures/mail/ecowas_boilerplate.html');
        self::assertIsString($html);

        $text = NewsletterBodyExtractor::fromHtml($html);
        self::assertStringContainsString('Arrival in Abidjan', $text);
        self::assertStringContainsString('ECOWAS', $text);
        self::assertStringNotContainsString('requires a modern e-mail reader', $text);
    }

    public function testEmptyHtmlReturnsEmpty(): void
    {
        self::assertSame('', NewsletterBodyExtractor::fromHtml(''));
    }
}
