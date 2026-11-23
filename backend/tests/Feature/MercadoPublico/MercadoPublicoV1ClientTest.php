<?php

namespace Tests\Feature\MercadoPublico;

use App\Domain\PublicProcurement\ChileRegion;
use App\Models\MercadoPublicoApiUsage;
use App\Services\MercadoPublico\Adapters\LicitacionesV1Adapter;
use App\Services\MercadoPublico\DTO\V1SearchQuery;
use App\Services\MercadoPublico\Exceptions\MercadoPublicoException;
use App\Services\MercadoPublico\Http\MercadoPublicoV1Client;
use App\Services\MercadoPublico\Normalizers\LicitacionNormalizer;
use App\Services\MercadoPublico\Normalizers\OrdenCompraNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Tests\TestCase;

class MercadoPublicoV1ClientTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_TICKET = '00000000-TEST-TICKET-0000-000000000000';

    private function client(): MercadoPublicoV1Client
    {
        return app(MercadoPublicoV1Client::class);
    }

    public function test_ticket_is_sent_as_query_parameter_and_date_uses_ddmmaaaa(): void
    {
        Http::fake(['api.mercadopublico.cl/*' => Http::response($this->fixture('licitaciones_list'))]);

        $records = app(LicitacionesV1Adapter::class)->search(new V1SearchQuery(
            date: CarbonImmutable::create(2026, 2, 3, 0, 0, 0, 'America/Santiago'),
            status: 'adjudicada',
        ));

        $this->assertCount(3, $records);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.mercadopublico.cl/servicios/v1/publico/licitaciones.json?')
            && $r['ticket'] === self::TEST_TICKET
            && $r['fecha'] === '03022026'
            && $r['estado'] === 'adjudicada'
            && ! $r->hasHeader('ticket'));
    }

    public function test_buyer_and_supplier_filters_default_to_today(): void
    {
        CarbonImmutable::setTestNow('2026-11-14 05:30:00'); // 02:30 del 14-11 en Santiago
        $q = new V1SearchQuery(buyerCode: '7000');
        $this->assertSame(['fecha' => '14112026', 'CodigoOrganismo' => '7000'], $q->toQuery('America/Santiago'));
        CarbonImmutable::setTestNow();
    }

    public function test_invalid_query_combinations_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new V1SearchQuery(status: 'activas', buyerCode: '7000');
    }

    public function test_html_error_page_is_mapped_and_ticket_never_logged(): void
    {
        Log::spy();
        Http::fake(['*' => Http::response('<html><title>Runtime Error</title>'.self::TEST_TICKET.'</html>', 400, ['Content-Type' => 'text/html'])]);

        try {
            $this->client()->get('/licitaciones.json', ['fecha' => '14112026']);
            $this->fail('Se esperaba excepción');
        } catch (MercadoPublicoException $e) {
            $this->assertSame(MercadoPublicoException::INVALID_PARAMETERS, $e->errorCode);
            $this->assertStringNotContainsString(self::TEST_TICKET, $e->getMessage());
        }

        Http::assertSentCount(1);
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
            return $message === 'mercado_publico.v1.request_failed'
                && ! str_contains(json_encode($context), self::TEST_TICKET)
                && $context['path'] === '/licitaciones.json';
        });
    }

    public function test_json_error_with_ticket_message_is_unauthorized_and_redacted(): void
    {
        Http::fake(['*' => Http::response(['Codigo' => 203, 'Mensaje' => 'Ticket no válido: '.self::TEST_TICKET])]);

        try {
            $this->client()->get('/licitaciones.json');
            $this->fail('Se esperaba excepción');
        } catch (MercadoPublicoException $e) {
            $this->assertSame(MercadoPublicoException::UNAUTHORIZED, $e->errorCode);
            $this->assertStringNotContainsString(self::TEST_TICKET, $e->getMessage());
            $this->assertStringContainsString('[REDACTED]', $e->getMessage());
        }
    }

    public function test_5xx_and_connection_errors_retry_with_limit(): void
    {
        Http::fake(['*' => Http::sequence()->push('x', 503)->pushFailedConnection()->push($this->fixture('licitaciones_list'))]);

        $json = $this->client()->get('/licitaciones.json');

        $this->assertSame(3, $json['Cantidad']);
        $this->assertSame(3, $this->client()->requestCount());
        $this->assertSame(3, (int) MercadoPublicoApiUsage::where('api', 'v1')->value('request_count'));
    }

    public function test_connection_failure_message_does_not_leak_url(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error for https://api.mercadopublico.cl/x?ticket='.self::TEST_TICKET));

        try {
            $this->client()->get('/licitaciones.json');
            $this->fail('Se esperaba excepción');
        } catch (MercadoPublicoException $e) {
            $this->assertSame(MercadoPublicoException::UPSTREAM_UNAVAILABLE, $e->errorCode);
            $this->assertStringNotContainsString('ticket', $e->getMessage());
        }
    }

    public function test_429_blocks_v1_until_next_day_without_retry(): void
    {
        Http::fake(['*' => Http::response('', 429)]);

        try {
            $this->client()->get('/licitaciones.json');
        } catch (MercadoPublicoException $e) {
            $this->assertSame(MercadoPublicoException::QUOTA_EXHAUSTED, $e->errorCode);
        }
        Http::assertSentCount(1);

        try {
            $this->client()->get('/licitaciones.json');
            $this->fail('Se esperaba bloqueo local');
        } catch (MercadoPublicoException $e) {
            $this->assertSame(MercadoPublicoException::QUOTA_EXHAUSTED, $e->errorCode);
        }
        Http::assertSentCount(1); // bloqueada localmente
    }

    public function test_licitacion_detail_normalization(): void
    {
        $raw = $this->fixture('licitacion_detail')['Listado'][0];
        $record = app(LicitacionNormalizer::class)->normalize($raw);

        $this->assertTrue($record->isDetail);
        $this->assertSame('9999-11-LE26', $record->code);
        $a = $record->attributes;
        $this->assertSame('5', $a['status_code']);
        $this->assertSame('Publicada', $a['status_label']);
        $this->assertSame('LE', $a['procedure_type']);
        $this->assertSame('7000', $a['buyer_code']);
        $this->assertSame(13, $a['region_code']);
        $this->assertSame('12500000.00', $a['estimated_budget']);
        $this->assertSame('12500000.00', $a['available_amount_clp']);
        $this->assertSame('Presupuesto disponible', $a['budget_type']);
        $this->assertSame('2026-11-28T18:00:00+00:00', $a['closes_at']->toIso8601String()); // 15:00 Chile (UTC-3)
        $this->assertSame('12 meses', $a['details']['contract_duration']);
        $this->assertArrayNotHasKey('site_visit_at', $a['details']['dates']); // fecha centinela 0001-01-01
        $this->assertCount(2, $record->items);
        $this->assertSame('PROVEEDOR FICTICIO SPA', $record->items[1]['awarded_supplier_name']);
        $this->assertSame('150000.00', $record->items[1]['awarded_unit_price']);

        // Minimización: sin datos personales de funcionarios.
        $json = json_encode($record->raw);
        $this->assertStringNotContainsString('PERSONA DE PRUEBA', $json);
        $this->assertStringNotContainsString('example.invalid', $json);
    }

    public function test_orden_compra_detail_normalization(): void
    {
        $record = app(OrdenCompraNormalizer::class)->normalize($this->fixture('orden_detail')['Listado'][0]);

        $a = $record->attributes;
        $this->assertSame(6, $a['status_code']);
        $this->assertSame('9999-11-LE26', $a['tender_code']);
        $this->assertSame('17793', $a['supplier_code']);
        $this->assertSame('76.000.000-0', $a['supplier_rut']);
        $this->assertSame('1190000.00', $a['total']);
        $this->assertSame('Despachar a dirección de envío', $a['details']['dispatch_type']);
        $this->assertSame('30 días contra recepción de factura', $a['details']['payment_terms']);
        $this->assertSame('1000000.00', $a['items'][0]['total']);
        $this->assertStringNotContainsString('example.invalid', json_encode($record->raw));
    }

    public function test_region_name_resolution(): void
    {
        $this->assertSame(13, ChileRegion::codeFromName('Región Metropolitana de Santiago'));
        $this->assertSame(6, ChileRegion::codeFromName("Región del Libertador General Bernardo O´Higgins"));
        $this->assertSame(14, ChileRegion::codeFromName('Región de Los Ríos'));
        $this->assertSame(8, ChileRegion::codeFromName('Región del Biobío'));
        $this->assertNull(ChileRegion::codeFromName('Extranjero'));
    }
}
