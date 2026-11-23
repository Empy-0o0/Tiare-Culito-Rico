<?php

namespace Tests\Feature\Api;

use App\Models\PublicOpportunityItem;
use App\Models\PublicProcurementOpportunity;
use App\Models\PublicPurchaseOrder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MercadoPublicoV1ApiTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_TICKET = '00000000-TEST-TICKET-0000-000000000000';

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->reader = $this->userWithTenantRole('tenant_read_only');
        CarbonImmutable::setTestNow('2026-11-14 13:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function fakeV1(): void
    {
        Http::fake(function (Request $r) {
            $path = parse_url($r->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/licitaciones.json') => Http::response($this->fixture(isset($r['codigo']) ? 'licitacion_detail' : 'licitaciones_list')),
                str_ends_with($path, '/ordenesdecompra.json') => Http::response($this->fixture(isset($r['codigo']) ? 'orden_detail' : 'ordenes_list')),
                str_ends_with($path, '/Empresas/BuscarComprador') => Http::response($this->fixture('buscar_comprador')),
                str_ends_with($path, '/Empresas/BuscarProveedor') => Http::response($this->fixture('buscar_proveedor')),
                default => Http::response('', 404),
            };
        });
    }

    public function test_tender_search_fetches_once_persists_and_reuses_cache(): void
    {
        $this->fakeV1();

        $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/tenders?date=2026-11-14&sort=closes_at')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.search.received', 3)
            ->assertJsonPath('meta.search.from_cache', false)
            ->assertJsonPath('meta.source.api', 'API Licitaciones V1')
            ->assertJsonPath('data.0.code', '9999-12-L126'); // cierre más próximo primero; null al final

        // Segunda página / filtro local: sin nueva solicitud.
        $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/tenders?date=2026-11-14&status=8')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('meta.search.from_cache', true);

        Http::assertSentCount(1);
        $this->assertSame(3, PublicProcurementOpportunity::where('source_type', 'licitacion')->count());
    }

    public function test_tender_detail_is_fetched_and_normalized_without_personal_data(): void
    {
        $this->fakeV1();

        $res = $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/tenders/9999-11-LE26')
            ->assertOk()
            ->assertJsonPath('data.detail_loaded', true)
            ->assertJsonPath('data.type.code', 'LE')
            ->assertJsonPath('data.buyer.code', '7000')
            ->assertJsonPath('data.buyer.region_code', 13)
            ->assertJsonPath('data.items.1.award.supplier_name', 'PROVEEDOR FICTICIO SPA')
            ->assertJsonPath('meta.stale', false);

        $body = $res->getContent();
        $this->assertStringNotContainsString(self::TEST_TICKET, $body);
        $this->assertStringNotContainsString('PERSONA DE PRUEBA', $body);
        $this->assertSame(2, PublicOpportunityItem::count());

        // Dentro de la ventana de caché no vuelve a llamar.
        $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/tenders/9999-11-LE26')->assertOk();
        Http::assertSentCount(1);
    }

    public function test_tender_detail_not_found(): void
    {
        Http::fake(['*' => Http::response(['Cantidad' => 0, 'FechaCreacion' => '2026-11-14T10:00:00', 'Version' => 'v1', 'Listado' => []])]);

        $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/tenders/0000-0-XX00')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'MP_NOT_FOUND');
    }

    public function test_stale_copy_is_returned_when_v1_fails(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            return ++$calls === 1 ? Http::response($this->fixture('licitacion_detail')) : Http::response('', 503);
        });
        $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/tenders/9999-11-LE26')->assertOk();

        $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/tenders/9999-11-LE26?refresh=1')
            ->assertOk()
            ->assertJsonPath('meta.stale', true)
            ->assertJsonPath('data.code', '9999-11-LE26');
    }

    public function test_purchase_orders_search_and_detail(): void
    {
        $this->fakeV1();

        $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/purchase-orders?date=2026-11-13&source_status=aceptada')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2);
        Http::assertSent(fn (Request $r) => $r['fecha'] === '13112026' && $r['estado'] === 'aceptada');

        $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/purchase-orders/9999-100-SE26')
            ->assertOk()
            ->assertJsonPath('data.supplier.code', '17793')
            ->assertJsonPath('data.amounts.total', '1190000.00')
            ->assertJsonPath('data.tender_code', '9999-11-LE26')
            ->assertJsonPath('data.items.0.unit_net_price', '10000.00')
            ->assertJsonMissingPath('data.raw_payload');

        $this->assertNotNull(PublicPurchaseOrder::where('external_code', '9999-100-SE26')->value('detail_fetched_at'));
    }

    public function test_query_validation(): void
    {
        $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/tenders?source_status=activas&buyer_code=7000')
            ->assertStatus(422);
        $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/tenders?date=2099-01-01')
            ->assertStatus(422);
        $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/purchase-orders?source_status=inventado')
            ->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_buyers_directory_loads_once_and_filters_locally(): void
    {
        $this->fakeV1();

        $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/buyers')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.refreshed', true);

        $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/buyers?q=hospital')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.code', '7002')
            ->assertJsonPath('meta.refreshed', false);

        Http::assertSentCount(1);
    }

    public function test_supplier_lookup_formats_rut_and_caches(): void
    {
        $this->fakeV1();

        $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/suppliers/lookup?rut=760000000')
            ->assertOk()
            ->assertJsonPath('data.code', '17793')
            ->assertJsonPath('data.rut', '76.000.000-0')
            ->assertJsonPath('meta.cached', false);
        Http::assertSent(fn (Request $r) => $r['rutempresaproveedor'] === '76.000.000-0');

        $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/suppliers/lookup?rut=76.000.000-0')
            ->assertOk()->assertJsonPath('meta.cached', true);
        Http::assertSentCount(1);

        $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/suppliers/lookup?rut=76.000.000-1')
            ->assertStatus(422);
    }

    public function test_v1_routes_require_authentication_and_permission(): void
    {
        $this->getJson('/api/v1/public-procurement/tenders')->assertUnauthorized();
        $this->getJson('/api/v1/public-procurement/buyers')->assertUnauthorized();
    }

    public function test_catalogs_include_v1_lists(): void
    {
        $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/catalogs')
            ->assertOk()
            ->assertJsonPath('data.tender_statuses.0.code', '5')
            ->assertJsonPath('data.tender_types.0.code', 'L1')
            ->assertJsonPath('data.order_status_filters.0.code', 'todos');
    }
}
