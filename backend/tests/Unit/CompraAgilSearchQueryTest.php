<?php

namespace Tests\Unit;

use App\Services\MercadoPublico\DTO\CompraAgilSearchQuery;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CompraAgilSearchQueryTest extends TestCase
{
    public function test_builds_documented_parameters(): void
    {
        $params = (new CompraAgilSearchQuery(
            changedWithinMs: 300000,
            statuses: ['publicada', 'proveedor_seleccionado'],
            regions: [13, 5, 13],
            keywords: '  materiales electricos ',
            pageSize: 50,
            page: 2,
            orderBy: CompraAgilSearchQuery::ORDER_PUBLISHED,
        ))->toQueryParams();

        $this->assertSame([
            'ttl_cambio_ms' => 300000,
            'estado' => 'publicada,proveedor_seleccionado',
            'region' => '13,5',
            'q' => 'materiales electricos',
            'tamano_pagina' => 50,
            'numero_pagina' => 2,
            'ordenar_por' => 'FechaPublicacion',
        ], $params);
        $this->assertArrayNotHasKey('ticket', $params);
    }

    public function test_id_and_q_are_mutually_exclusive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CompraAgilSearchQuery(code: '1057539-228-COT26', keywords: 'aseo'))->toQueryParams();
    }

    public function test_ttl_cannot_be_combined_with_change_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CompraAgilSearchQuery(changedWithinMs: 1000, changedFrom: CarbonImmutable::now()->subDay(), changedTo: CarbonImmutable::now()))->toQueryParams();
    }

    public function test_change_range_requires_both_ends(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CompraAgilSearchQuery(changedFrom: CarbonImmutable::now()))->toQueryParams();
    }

    public function test_page_size_cannot_exceed_50(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CompraAgilSearchQuery(pageSize: 51))->toQueryParams();
    }

    public function test_page_size_below_real_minimum_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CompraAgilSearchQuery(pageSize: 9))->toQueryParams();
    }

    public function test_invalid_region_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CompraAgilSearchQuery(regions: [17]))->toQueryParams();
    }

    public function test_invalid_status_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CompraAgilSearchQuery(statuses: ['adjudicada']))->toQueryParams();
    }

    public function test_change_range_is_formatted_iso8601_utc(): void
    {
        $params = (new CompraAgilSearchQuery(
            changedFrom: CarbonImmutable::parse('2026-04-01T00:00:00Z'),
            changedTo: CarbonImmutable::parse('2026-04-02T00:00:00Z'),
        ))->toQueryParams();

        $this->assertSame('2026-04-01T00:00:00Z', $params['cambio_desde']);
        $this->assertSame('2026-04-02T00:00:00Z', $params['cambio_hasta']);
    }

    public function test_code_format_validation(): void
    {
        $this->assertTrue(CompraAgilSearchQuery::isValidCode('1057539-228-COT26'));
        $this->assertFalse(CompraAgilSearchQuery::isValidCode('../../etc'));
        $this->assertFalse(CompraAgilSearchQuery::isValidCode('1057539 228'));
        $this->assertFalse(CompraAgilSearchQuery::isValidCode('https://evil.test'));
    }
}
