<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\IpReputation;

use App\Service\IpReputation\IpReputationAllowlist;
use PHPUnit\Framework\TestCase;

final class IpReputationAllowlistTest extends TestCase
{
    private IpReputationAllowlist $allowlist;

    protected function setUp(): void
    {
        $this->allowlist = new IpReputationAllowlist();
    }

    public function testExactEmailCaseInsensitive(): void
    {
        self::assertTrue($this->allowlist->matches("Bob@Example.com\n", '1.2.3.4', 'bob@example.com'));
        self::assertFalse($this->allowlist->matches("bob@example.com\n", '1.2.3.4', 'alice@example.com'));
    }

    public function testEmailDomainWildcard(): void
    {
        self::assertTrue($this->allowlist->matches('@example.com', '1.2.3.4', 'anyone@example.com'));
        self::assertFalse($this->allowlist->matches('@example.com', '1.2.3.4', 'anyone@notexample.com'));
        self::assertFalse($this->allowlist->matches('@example.com', '1.2.3.4', 'anyone@evil-example.com.attacker.net'));
    }

    public function testExactIp(): void
    {
        self::assertTrue($this->allowlist->matches('203.0.113.7', '203.0.113.7', 'x@y.z'));
        self::assertFalse($this->allowlist->matches('203.0.113.7', '203.0.113.8', 'x@y.z'));
    }

    public function testCidrV4(): void
    {
        self::assertTrue($this->allowlist->matches('10.0.0.0/8', '10.20.30.40', 'x@y.z'));
        self::assertFalse($this->allowlist->matches('10.0.0.0/8', '11.0.0.1', 'x@y.z'));
    }

    public function testCidrV6(): void
    {
        self::assertTrue($this->allowlist->matches('2001:db8::/32', '2001:db8::1', 'x@y.z'));
        self::assertFalse($this->allowlist->matches('2001:db8::/32', '2001:db9::1', 'x@y.z'));
    }

    public function testCommentsAndBlankLinesIgnored(): void
    {
        $raw = "# team\n\nbob@example.com\n";
        self::assertTrue($this->allowlist->matches($raw, '1.2.3.4', 'bob@example.com'));
    }

    public function testEmptyListMatchesNothing(): void
    {
        self::assertFalse($this->allowlist->matches('', '1.2.3.4', 'x@y.z'));
    }

    public function testMalformedCidrRejectsAll(): void
    {
        self::assertFalse($this->allowlist->matches('10.0.0.0/33', '10.20.30.40', 'x@y.z'));
        self::assertFalse($this->allowlist->matches('10.0.0.0/', '10.20.30.40', 'x@y.z'));
        self::assertFalse($this->allowlist->matches('10.0.0.0/x', '10.20.30.40', 'x@y.z'));
    }

    public function testMixedFamilyCidrRejectsAll(): void
    {
        self::assertFalse($this->allowlist->matches('2001:db8::/32', '10.20.30.40', 'x@y.z'));
        self::assertFalse($this->allowlist->matches('10.0.0.0/8', '2001:db8::1', 'x@y.z'));
    }

    public function testExactIpv6CompressedVsExpanded(): void
    {
        self::assertTrue($this->allowlist->matches('2001:0db8:0000:0000:0000:0000:0000:0001', '2001:db8::1', 'x@y.z'));
        self::assertTrue($this->allowlist->matches('2001:db8::1', '2001:0db8:0000:0000:0000:0000:0000:0001', 'x@y.z'));
    }
}
