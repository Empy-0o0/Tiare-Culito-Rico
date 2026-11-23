<?php

namespace Tests\Unit;

use App\Support\SourceDateParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SourceDateParserTest extends TestCase
{
    private const TZ = 'America/Santiago';

    public function test_naive_datetime_is_interpreted_as_santiago_time(): void
    {
        // 14-11-2026: Chile en horario de verano (UTC-3).
        $this->assertSame('2026-11-16T14:00:00+00:00', SourceDateParser::parse('2026-11-16 11:00', self::TZ, true)?->toIso8601String());
    }

    public function test_z_suffix_is_local_when_configured(): void
    {
        $this->assertSame('2026-11-16T14:00:00+00:00', SourceDateParser::parse('2026-11-16T11:00:00Z', self::TZ, true)?->toIso8601String());
    }

    public function test_z_suffix_is_utc_when_configured(): void
    {
        $this->assertSame('2026-11-16T11:00:00+00:00', SourceDateParser::parse('2026-11-16T11:00:00Z', self::TZ, false)?->toIso8601String());
    }

    public function test_explicit_offset_is_respected(): void
    {
        $this->assertSame('2026-06-01T16:00:00+00:00', SourceDateParser::parse('2026-06-01T12:00:00-04:00', self::TZ, true)?->toIso8601String());
    }

    public function test_milliseconds_with_z_are_parsed(): void
    {
        $this->assertSame('2026-11-14T01:00:00+00:00', SourceDateParser::parse('2026-11-13T22:00:00.350Z', self::TZ, true)?->startOfSecond()->toIso8601String());
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_values_return_null(mixed $value): void
    {
        $this->assertNull(SourceDateParser::parse($value, self::TZ, true));
    }

    public static function invalidValues(): array
    {
        return [[null], [''], ['   '], ['no-es-fecha'], [12345], [['x']], ['13/11/2026']];
    }
}
