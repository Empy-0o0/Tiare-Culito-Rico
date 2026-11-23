<?php

namespace Tests\Feature\MercadoPublico;

use App\Domain\PublicProcurement\MercadoPublicoV1Catalog;
use App\Services\MercadoPublico\Adapters\EmpresasV1Adapter;
use App\Services\MercadoPublico\Exceptions\MercadoPublicoException;
use App\Services\MercadoPublico\Http\MercadoPublicoV1Client;
use App\Services\MercadoPublico\Normalizers\LicitacionNormalizer;
use App\Services\MercadoPublico\Normalizers\OrdenCompraNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PEND-T10: comportamiento observado con respuestas reales de la API V1 (17-11-2026).
 * Los fixtures real_*.json conservan la estructura real y tienen anonimizados los datos de personas.
 */
class RealV1ResponsesTest extends TestCase
{
    use RefreshDatabase;

    private function real(string $name): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/mercado_publico/real_{$name}.json")), true);
    }

    public function test_real_tender_detail_normalizes_with_dates_in_fechas_block(): void
    {
        $raw = $this->real('licitacion_detail')['Listado'][0];
        $this->assertNull($raw['FechaCierre']); // en el detalle real, el cierre viene solo en Fechas.FechaCierre

        $r = app(LicitacionNormalizer::class)->normalize($raw);
        $this->assertTrue($r->isDetail);
        $this->assertSame('Cerrada', $r->attributes['status_label']);
        $this->assertSame('2026-11-16 18:01', $r->attributes['closes_at']->utc()->format('Y-m-d H:i')); // 15:01 hora de Chile
        $this->assertSame(10, $r->attributes['region_code']); // "Región de los Lagos " (con espacio final)
        $this->assertSame('LE', $r->attributes['procedure_type']);
        $this->assertCount(1, $r->items);
        $this->assertSame('777.0000', $r->items[0]['quantity']);
    }

    public function test_real_tender_list_statuses_15_and_16_have_labels(): void
    {
        $labels = [];
        foreach ($this->real('licitaciones_list')['Listado'] as $raw) {
            $r = app(LicitacionNormalizer::class)->normalize($raw);
            $labels[$r->attributes['status_code']] = $r->attributes['status_label'];
            $this->assertNotNull($r->attributes['closes_at']);
        }
        $this->assertSame('Revocada', $labels['15']);
        $this->assertSame('Suspendida', $labels['16']);
    }

    public function test_real_purchase_order_detail_normalizes(): void
    {
        $r = app(OrdenCompraNormalizer::class)->normalize($this->real('orden_detail')['Listado'][0]);
        $this->assertSame('Enviada a proveedor', $r->attributes['status_label']);
        $this->assertSame('CLP', $r->attributes['currency']);
        $this->assertSame('PROVEEDOR FICTICIO SPA', $r->attributes['supplier_name']);
        $this->assertNotNull($r->attributes['sent_at']);
        $this->assertCount(2, $r->items);
    }

    public function test_simultaneous_requests_429_is_retried_and_not_treated_as_quota(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['Codigo' => 10500, 'Mensaje' => 'Lo sentimos. Hemos detectado que existen peticiones simultáneas.'], 429)
            ->push($this->real('licitaciones_list'))
            ->push($this->real('licitaciones_list'))]);

        $json = app(MercadoPublicoV1Client::class)->get('/licitaciones.json', ['fecha' => '16112026']);
        $this->assertCount(6, $json['Listado']);
        Http::assertSentCount(2);

        // No quedó bloqueada por cuota: la siguiente consulta sale normalmente.
        app(MercadoPublicoV1Client::class)->get('/licitaciones.json', ['fecha' => '15112026']);
        Http::assertSentCount(3);
    }

    public function test_invalid_filter_500_is_a_parameter_error_without_retries(): void
    {
        Http::fake(['*' => Http::response(['Codigo' => 400, 'Mensaje' => 'Los parámetros no son válidos.'], 500)]);
        try {
            app(MercadoPublicoV1Client::class)->get('/ordenesdecompra.json', ['fecha' => '16112026', 'estado' => 'enproceso']);
            $this->fail('Se esperaba error de parámetros');
        } catch (MercadoPublicoException $e) {
            $this->assertSame(MercadoPublicoException::INVALID_PARAMETERS, $e->errorCode);
            $this->assertFalse($e->isRetryable());
        }
        Http::assertSentCount(1);
    }

    public function test_order_status_filters_only_offer_values_validated_against_the_api(): void
    {
        $this->assertSame(['todos', 'enviadaproveedor', 'aceptada', 'cancelada', 'recepcionconforme', 'pendienterecepcion'], array_keys(MercadoPublicoV1Catalog::ORDER_STATUS_FILTERS));
    }

    public function test_supplier_lookup_with_several_unrelated_results_is_not_a_match(): void
    {
        Http::fake(['*' => Http::response(['Cantidad' => 2, 'FechaCreacion' => '2026-11-17T12:00:00Z', 'listaEmpresas' => [
            ['CodigoEmpresa' => '11111', 'NombreEmpresa' => 'Empresa Ficticia Uno'],
            ['CodigoEmpresa' => '22222', 'NombreEmpresa' => 'Empresa Ficticia Dos'],
        ]])]);
        $this->assertNull(app(EmpresasV1Adapter::class)->supplierByRut('1.111.111-1'));
    }

    public function test_real_buyers_directory_shape(): void
    {
        Http::fake(['*' => Http::response($this->real('buscar_comprador'))]);
        $buyers = app(EmpresasV1Adapter::class)->buyers();
        $this->assertCount(3, $buyers);
        $this->assertNotSame('', $buyers[0]['code']);
    }
}
