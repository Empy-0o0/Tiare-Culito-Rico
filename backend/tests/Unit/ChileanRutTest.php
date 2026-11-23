<?php

namespace Tests\Unit;

use App\Support\ChileanRut;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ChileanRutTest extends TestCase
{
    #[DataProvider('validRuts')]
    public function test_valid_ruts_are_accepted_and_formatted(string $input, string $formatted): void
    {
        $this->assertTrue(ChileanRut::isValid($input));
        $this->assertSame($formatted, ChileanRut::format($input));
    }

    public static function validRuts(): array
    {
        // Dígitos verificadores calculados por módulo 11 (incluye DV 0 y K).
        return [
            ['11111111-1', '11.111.111-1'],
            ['12.345.678-5', '12.345.678-5'],
            ['123456785', '12.345.678-5'],
            ['61.981.300-6', '61.981.300-6'], // RUT de organismo público observado en la API
            ['10000013-K', '10.000.013-K'],
            ['10000013-k', '10.000.013-K'],
            ['1000013-0', '1.000.013-0'],
            ['1000005-K', '1.000.005-K'],
        ];
    }

    #[DataProvider('invalidRuts')]
    public function test_invalid_ruts_are_rejected(?string $input): void
    {
        $this->assertFalse(ChileanRut::isValid($input));
        $this->assertNull(ChileanRut::format($input));
    }

    public static function invalidRuts(): array
    {
        return [[null], [''], ['12.345.678-9'], ['abc'], ['1-9'], ['123456789012-3'], ['10000013-0']];
    }
}
