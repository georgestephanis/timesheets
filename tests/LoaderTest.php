<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class LoaderTest extends TestCase
{
    // bsearchRight

    public function testBsearchRightEmpty(): void
    {
        $this->assertSame(0, bsearchRight([], 5.0));
    }

    public function testBsearchRightBeforeAll(): void
    {
        $this->assertSame(0, bsearchRight([10.0, 20.0, 30.0], 5.0));
    }

    public function testBsearchRightAfterAll(): void
    {
        $this->assertSame(3, bsearchRight([10.0, 20.0, 30.0], 99.0));
    }

    public function testBsearchRightExactMatch(): void
    {
        // Upper-bound: element equal to key is to the LEFT of the returned index
        $this->assertSame(2, bsearchRight([10.0, 20.0, 30.0], 20.0));
    }

    public function testBsearchRightBetweenValues(): void
    {
        $this->assertSame(1, bsearchRight([10.0, 20.0, 30.0], 15.0));
    }

    public function testBsearchRightSingleElement(): void
    {
        $this->assertSame(1, bsearchRight([10.0], 10.0));
    }

    // awEpochToDateTime

    public function testAwEpochSeconds(): void
    {
        // 10-digit unix timestamp
        $dt = awEpochToDateTime(1_700_000_000);
        $this->assertInstanceOf(DateTimeImmutable::class, $dt);
        $this->assertSame('2023-11-14', $dt->format('Y-m-d'));
    }

    public function testAwEpochMilliseconds(): void
    {
        // 13-digit
        $dt = awEpochToDateTime(1_700_000_000_000);
        $this->assertInstanceOf(DateTimeImmutable::class, $dt);
        $this->assertSame('2023-11-14', $dt->format('Y-m-d'));
    }

    public function testAwEpochMicroseconds(): void
    {
        // 16-digit
        $dt = awEpochToDateTime(1_700_000_000_000_000);
        $this->assertInstanceOf(DateTimeImmutable::class, $dt);
        $this->assertSame('2023-11-14', $dt->format('Y-m-d'));
    }

    public function testAwEpochZeroReturnsNull(): void
    {
        $this->assertNull(awEpochToDateTime(0));
    }

    public function testAwEpochNegativeReturnsNull(): void
    {
        $this->assertNull(awEpochToDateTime(-1));
    }

    public function testAwEpochNullReturnsNull(): void
    {
        $this->assertNull(awEpochToDateTime(null));
    }

    public function testAwEpochStringTimestamp(): void
    {
        $dt = awEpochToDateTime('1700000000');
        $this->assertInstanceOf(DateTimeImmutable::class, $dt);
    }

    // backfillChromeUrls — uses event start, not midpoint

    public function testBackfillPicksVisitBeforeStart(): void
    {
        // Visit at t=100, event start=110, end=300 — visit is within the default 120s window.
        $base  = new DateTimeImmutable('@0', new DateTimeZone('UTC'));
        $visit = $base->modify('+100 seconds');
        $eStart = $base->modify('+110 seconds');
        $eEnd   = $base->modify('+300 seconds');

        $chrome = [['time' => $visit, 'host' => 'example.com', 'url' => 'https://example.com/', 'title' => 'Ex']];
        $events = [
            'window' => [['app' => 'Google Chrome', 'title' => '', 'url' => '', 'start' => $eStart, 'end' => $eEnd]],
            'afk'    => [],
            'input'  => [],
        ];

        backfillChromeUrls($events, $chrome, 120);
        $this->assertSame('https://example.com/', $events['window'][0]['url']);
    }

    public function testBackfillIgnoresVisitAfterStart(): void
    {
        // Visit at t=200, event start=110 — visit is AFTER start, should NOT be matched.
        $base  = new DateTimeImmutable('@0', new DateTimeZone('UTC'));
        $visit  = $base->modify('+200 seconds');
        $eStart = $base->modify('+110 seconds');
        $eEnd   = $base->modify('+190 seconds');

        $chrome = [['time' => $visit, 'host' => 'example.com', 'url' => 'https://example.com/', 'title' => 'Ex']];
        $events = [
            'window' => [['app' => 'Google Chrome', 'title' => '', 'url' => '', 'start' => $eStart, 'end' => $eEnd]],
            'afk'    => [],
            'input'  => [],
        ];

        backfillChromeUrls($events, $chrome, 120);
        $this->assertSame('', $events['window'][0]['url']);
    }

    public function testBackfillRespectsWindowSeconds(): void
    {
        // Visit at t=0, event start=200 — gap is 200s, beyond the 120s window.
        $base  = new DateTimeImmutable('@0', new DateTimeZone('UTC'));
        $visit  = $base;
        $eStart = $base->modify('+200 seconds');
        $eEnd   = $base->modify('+300 seconds');

        $chrome = [['time' => $visit, 'host' => 'example.com', 'url' => 'https://example.com/', 'title' => 'Ex']];
        $events = [
            'window' => [['app' => 'Google Chrome', 'title' => '', 'url' => '', 'start' => $eStart, 'end' => $eEnd]],
            'afk'    => [],
            'input'  => [],
        ];

        backfillChromeUrls($events, $chrome, 120);
        $this->assertSame('', $events['window'][0]['url']);
    }
}
