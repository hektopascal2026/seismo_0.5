<?php

declare(strict_types=1);

namespace Seismo\Tests;

use PHPUnit\Framework\TestCase;
use Seismo\Repository\EmailSubscriptionRepository;

final class EmailSubscriptionRepositoryTest extends TestCase
{
    public function testDomainMatchesSubaddress(): void
    {
        self::assertTrue(EmailSubscriptionRepository::matchesAddress(
            'alice@example.com',
            'domain',
            'example.com'
        ));
        self::assertTrue(EmailSubscriptionRepository::matchesAddress(
            'alice@example.com',
            'domain',
            '@example.com'
        ));
    }

    public function testDomainDoesNotMatchOtherDomain(): void
    {
        self::assertFalse(EmailSubscriptionRepository::matchesAddress(
            'alice@other.com',
            'domain',
            'example.com'
        ));
    }

    public function testEmailExactMatchIsCaseInsensitive(): void
    {
        self::assertTrue(EmailSubscriptionRepository::matchesAddress(
            'Alice@Example.COM',
            'email',
            'alice@example.com'
        ));
    }

    public function testEmailDoesNotMatchPartial(): void
    {
        self::assertFalse(EmailSubscriptionRepository::matchesAddress(
            'alice@example.com',
            'email',
            'bob@example.com'
        ));
    }
}
