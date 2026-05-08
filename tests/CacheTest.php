<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class CacheTest extends TestCase
{
    private function makeEvent(string $start, string $end, array $extra = []): array
    {
        return array_merge([
            'start' => new DateTimeImmutable($start),
            'end'   => new DateTimeImmutable($end),
            'app'   => 'TestApp',
            'title' => 'Test',
        ], $extra);
    }

    // serializeEvents / deserializeEvents round-trip

    public function testEventsRoundTrip(): void
    {
        $events = [
            'window' => [$this->makeEvent('2024-01-15T10:00:00Z', '2024-01-15T10:30:00Z')],
            'afk'    => [$this->makeEvent('2024-01-15T10:05:00Z', '2024-01-15T10:10:00Z', ['status' => 'afk'])],
            'input'  => [],
        ];

        $serialized   = serializeEvents($events);
        $deserialized = deserializeEvents($serialized);

        $this->assertSame(
            $events['window'][0]['start']->getTimestamp(),
            $deserialized['window'][0]['start']->getTimestamp()
        );
        $this->assertInstanceOf(DateTimeImmutable::class, $deserialized['window'][0]['start']);
        $this->assertInstanceOf(DateTimeImmutable::class, $deserialized['afk'][0]['end']);
    }

    public function testEventsSerializeToStrings(): void
    {
        $events = [
            'window' => [$this->makeEvent('2024-01-15T10:00:00Z', '2024-01-15T10:30:00Z')],
            'afk'    => [],
            'input'  => [],
        ];
        $serialized = serializeEvents($events);
        $this->assertIsString($serialized['window'][0]['start']);
    }

    public function testEventsEmptyRoundTrip(): void
    {
        $events = ['window' => [], 'afk' => [], 'input' => []];
        $this->assertSame($events, deserializeEvents(serializeEvents($events)));
    }

    // serializeChrome / deserializeChrome round-trip

    public function testChromeRoundTrip(): void
    {
        $chrome = [[
            'time'  => new DateTimeImmutable('2024-01-15T12:00:00Z'),
            'host'  => 'example.com',
            'url'   => 'https://example.com/page',
            'title' => 'Example',
        ]];

        $deserialized = deserializeChrome(serializeChrome($chrome));

        $this->assertInstanceOf(DateTimeImmutable::class, $deserialized[0]['time']);
        $this->assertSame($chrome[0]['time']->getTimestamp(), $deserialized[0]['time']->getTimestamp());
        $this->assertSame('example.com', $deserialized[0]['host']);
    }

    public function testChromeSerializeToString(): void
    {
        $chrome = [[
            'time'  => new DateTimeImmutable('2024-01-15T12:00:00Z'),
            'host'  => 'example.com',
            'url'   => 'https://example.com/',
            'title' => 'Example',
        ]];
        $serialized = serializeChrome($chrome);
        $this->assertIsString($serialized[0]['time']);
    }

    // serializeCommits / deserializeCommits round-trip

    public function testCommitsRoundTrip(): void
    {
        $commits = [[
            'dt'      => new DateTimeImmutable('2024-01-15T14:30:00Z'),
            'project' => 'MyProject',
            'repo'    => 'owner/repo',
            'sha'     => 'abc1234',
            'subj'    => 'Fix a bug',
        ]];

        $deserialized = deserializeCommits(serializeCommits($commits));

        $this->assertInstanceOf(DateTimeImmutable::class, $deserialized[0]['dt']);
        $this->assertSame($commits[0]['dt']->getTimestamp(), $deserialized[0]['dt']->getTimestamp());
        $this->assertSame('Fix a bug', $deserialized[0]['subj']);
    }

    // serializeExternal / deserializeExternal round-trip

    public function testExternalRoundTrip(): void
    {
        $rows = [[
            'start'   => new DateTimeImmutable('2024-01-15T09:00:00Z'),
            'end'     => new DateTimeImmutable('2024-01-15T10:00:00Z'),
            'project' => 'Acme',
            'notes'   => 'Meeting',
        ]];

        $deserialized = deserializeExternal(serializeExternal($rows));

        $this->assertInstanceOf(DateTimeImmutable::class, $deserialized[0]['start']);
        $this->assertInstanceOf(DateTimeImmutable::class, $deserialized[0]['end']);
        $this->assertSame($rows[0]['start']->getTimestamp(), $deserialized[0]['start']->getTimestamp());
        $this->assertSame('Meeting', $deserialized[0]['notes']);
    }

    public function testExternalSerializeToStrings(): void
    {
        $rows = [[
            'start' => new DateTimeImmutable('2024-01-15T09:00:00Z'),
            'end'   => new DateTimeImmutable('2024-01-15T10:00:00Z'),
        ]];
        $serialized = serializeExternal($rows);
        $this->assertIsString($serialized[0]['start']);
        $this->assertIsString($serialized[0]['end']);
    }
}
