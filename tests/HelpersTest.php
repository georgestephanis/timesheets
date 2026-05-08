<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase
{
    // fmtDur

    public function testFmtDurSeconds(): void
    {
        $this->assertSame('18s', fmtDur(18));
    }

    public function testFmtDurMinutes(): void
    {
        $this->assertSame('3m', fmtDur(180));
    }

    public function testFmtDurHoursAndMinutes(): void
    {
        $this->assertSame('1h 01m', fmtDur(3661));
    }

    public function testFmtDurExactHour(): void
    {
        $this->assertSame('1h 00m', fmtDur(3600));
    }

    public function testFmtDurZero(): void
    {
        $this->assertSame('0s', fmtDur(0));
    }

    public function testFmtDurFloat(): void
    {
        $this->assertSame('1m', fmtDur(89.9));
    }

    // chromeTime

    public function testChromeTimeKnownEpoch(): void
    {
        // Unix epoch 0 = 1970-01-01T00:00:00Z
        // Chrome time for that = 11_644_473_600 * 1_000_000 microseconds
        $ct = 11_644_473_600 * 1_000_000;
        $tz = new DateTimeZone('UTC');
        $dt = chromeTime($ct, $tz);
        $this->assertSame('1970-01-01T00:00:00+00:00', $dt->format('c'));
    }

    public function testChromeTimeTimezone(): void
    {
        $ct = 11_644_473_600 * 1_000_000; // Unix 0
        $tz = new DateTimeZone('America/New_York');
        $dt = chromeTime($ct, $tz);
        $this->assertSame('1969-12-31', $dt->format('Y-m-d'));
    }

    // fnmatchAny

    public function testFnmatchAnyMatch(): void
    {
        $this->assertTrue(fnmatchAny('foo.example.com', ['*.example.com']));
    }

    public function testFnmatchAnyNoMatch(): void
    {
        $this->assertFalse(fnmatchAny('bar.other.com', ['*.example.com']));
    }

    public function testFnmatchAnyEmpty(): void
    {
        $this->assertFalse(fnmatchAny('anything', []));
    }

    public function testFnmatchAnyCaseInsensitive(): void
    {
        $this->assertTrue(fnmatchAny('FOO.EXAMPLE.COM', ['*.example.com']));
    }

    // hostMatchesDomain

    public function testHostMatchesDomainExact(): void
    {
        $this->assertTrue(hostMatchesDomain('example.com', 'example.com'));
    }

    public function testHostMatchesDomainSubdomain(): void
    {
        $this->assertTrue(hostMatchesDomain('www.example.com', 'example.com'));
    }

    public function testHostMatchesDomainDeepSubdomain(): void
    {
        $this->assertTrue(hostMatchesDomain('api.staging.example.com', 'example.com'));
    }

    public function testHostMatchesDomainNoMatch(): void
    {
        $this->assertFalse(hostMatchesDomain('notexample.com', 'example.com'));
    }

    public function testHostMatchesDomainGlob(): void
    {
        $this->assertTrue(hostMatchesDomain('staging.example.com', '*.example.com'));
    }

    public function testHostMatchesDomainGlobDoesNotMatchApex(): void
    {
        // *.example.com should NOT match the bare apex via glob — glob semantics only
        $this->assertFalse(hostMatchesDomain('example.com', '*.example.com'));
    }

    public function testHostMatchesDomainEmptyHost(): void
    {
        $this->assertFalse(hostMatchesDomain('', 'example.com'));
    }

    public function testHostMatchesDomainEmptyPattern(): void
    {
        $this->assertFalse(hostMatchesDomain('example.com', ''));
    }
}
