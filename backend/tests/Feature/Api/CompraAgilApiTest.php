<?php

namespace Tests\Feature\Api;

use App\Jobs\SyncCompraAgilJob;
use App\Models\PublicProcurementOpportunity;
use App\Models\User;
use App\Services\MercadoPublico\Sync\MercadoPublicoSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CompraAgilApiTest extends TestCase
{
    use RefreshDatabase;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->reader = $this->userWithTenantRole('tenant_read_only');
        CarbonImmutable::setTestNow('2026-11-14 05:30:00'); // 02:30 en Santiago
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function seedOpportunities(): void
    {
        Http::fake(fn (Request $r) => Http::response($this->fixture((int) $r['numero_pagina'] === 2 ? 'compra_agil_list_page2' : 'compra_agil_list_page1')));
        app(MercadoPublicoSyncService::class)->syncCompraAgil();
    }

    public function test_list_returns_normalized_data_with_source_and_sync_metadata(): void
    {
        $this->seedOpportunities();

        $response = $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/compra-agil')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.source.name', 'Dirección ChileCompra / Mercado Público')
            ->assertJsonPath('meta.source.data_origin', 'official')
            ->assertJsonPath('meta.sync.last_status', 'succeeded')
            ->assertJsonStructure(['data' => [['public_id', 'code', 'title', 'status' => ['code', 'label'], 'dates' => ['published_at', 'closes_at'], 'buyer' => ['name', 'rut', 'region_code'], 'budget' => ['currency', 'available_clp'], 'purchase_order' => ['known', 'issued'], 'freshness' => ['last_fetched_at']]]]);

        $body = $response->getContent();
        $this->assertStringNotContainsString((string) config('mercado_publico.ticket'), $body);
        $this->assertStringNotContainsString('"id":', $body, 'No se exponen ids internos');
        $this->assertStringNotContainsString('payload_hash', $body);
        $response->assertJsonPath('data.0.purchase_order.issued', null); // listado no confirma OC
    }

    public function test_list_filters_and_allowlisted_sort(): void
    {
        $this->seedOpportunities();
        $as = $this->actingAs($this->reader);

        $as->getJson('/api/v1/public-procurement/compra-agil?region=5')->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.code', '9000002-22-COT26');
        $as->getJson('/api/v1/public-procurement/compra-agil?status=publicada,cerrada')->assertJsonPath('meta.pagination.total', 2);
        $as->getJson('/api/v1/public-procurement/compra-agil?q=ASEO')->assertJsonPath('meta.pagination.total', 1);
        $as->getJson('/api/v1/public-procurement/compra-agil?buyer_rut=61.000.003-3')->assertJsonPath('meta.pagination.total', 1);
        $as->getJson('/api/v1/public-procurement/compra-agil?open_only=1')->assertJsonPath('meta.pagination.total', 1);
        $as->getJson('/api/v1/public-procurement/compra-agil?sort=available_amount_clp&direction=asc')->assertJsonPath('data.0.code', '9000003-33-COT26');
        $as->getJson('/api/v1/public-procurement/compra-agil?sort=password')->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $as->getJson('/api/v1/public-procurement/compra-agil?region=17')->assertStatus(422);
        $as->getJson('/api/v1/public-procurement/compra-agil?q=100%25')->assertOk()->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_list_reads_local_database_and_never_calls_external_api(): void
    {
        Http::fake();
        $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/compra-agil')->assertOk();
        Http::assertNothingSent();
    }

    public function test_detail_fetches_from_api_then_serves_from_cache(): void
    {
        Http::fake(['api2.mercadopublico.cl/v2/compra-agil/*' => Http::response($this->fixture('compra_agil_detail'))]);

        $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/compra-agil/9000002-22-COT26')
            ->assertOk()
            ->assertJsonPath('data.code', '9000002-22-COT26')
            ->assertJsonPath('data.purchase_order.known', true)
            ->assertJsonPath('data.purchase_order.issued', true)
            ->assertJsonPath('data.purchase_order.source_id', '77777777')
            ->assertJsonPath('data.delivery.days', 5)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonCount(2, 'data.documents')
            ->assertJsonPath('meta.stale', false)
            ->assertJsonMissingPath('data.proveedores_cotizando')
            ->assertJsonCount(1, 'data.quotes')
            ->assertJsonPath('data.quotes.0.supplier_name', 'PROVEEDOR FICTICIO SPA')
            ->assertJsonPath('data.quotes.0.total_amount', '833000')
            ->assertJsonPath('data.quotes.0.is_small_business', true);

        $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/compra-agil/9000002-22-COT26')->assertOk();
        Http::assertSentCount(1); // segunda lectura desde caché local
    }

    public function test_detail_returns_stale_copy_when_api_fails(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->fixture('compra_agil_detail'))
            ->push(['success' => 'NOK', 'payload' => null, 'errors' => []], 503)
            ->push(['success' => 'NOK', 'payload' => null, 'errors' => []], 503)
            ->push(['success' => 'NOK', 'payload' => null, 'errors' => []], 503)]);

        $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/compra-agil/9000002-22-COT26')->assertOk();
        $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/compra-agil/9000002-22-COT26?refresh=1')
            ->assertOk()->assertJsonPath('meta.stale', true)
            ->assertJsonPath('meta.warning', 'Mercado Público no está disponible temporalmente.');
    }

    public function test_detail_not_found_and_invalid_code(): void
    {
        Http::fake(['*' => Http::response(['success' => 'NOK', 'payload' => null, 'errors' => [['codigo' => '404']]], 404)]);

        $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/compra-agil/1-1-COT26')
            ->assertStatus(404)->assertJsonPath('error.code', 'MP_NOT_FOUND');
        $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/compra-agil/abc')
            ->assertStatus(422);
    }

    public function test_quota_exhausted_is_reported_as_429_with_retry_after(): void
    {
        Http::fake(['*' => Http::response(['success' => 'NOK', 'payload' => null, 'errors' => [['codigo' => '429']]], 429, ['Retry-After' => '600'])]);

        $response = $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/compra-agil/1-1-COT26')
            ->assertStatus(429)->assertJsonPath('error.code', 'MP_QUOTA_EXHAUSTED');
        $this->assertSame('600', $response->headers->get('Retry-After'));
    }

    public function test_sync_requires_sync_permission(): void
    {
        Queue::fake();
        $this->actingAs($this->reader)->postJson('/api/v1/public-procurement/sync')->assertStatus(403);
        Queue::assertNothingPushed();

        $analyst = $this->platformUser('public_procurement_analyst');
        $this->actingAs($analyst)->postJson('/api/v1/public-procurement/sync')->assertStatus(202);
        Queue::assertPushed(SyncCompraAgilJob::class, fn ($job) => $job->userId === $analyst->id && $job->trigger === 'api');
        $this->assertDatabaseHas('audit_logs', ['action' => 'public_procurement.sync.requested', 'user_id' => $analyst->id]);
    }

    public function test_sync_status_reports_quota_and_runs_without_ticket(): void
    {
        $this->seedOpportunities();

        $response = $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/sync-status')
            ->assertOk()
            ->assertJsonPath('data.integration.configured', true)
            ->assertJsonPath('data.quota.v2.used', 2)
            ->assertJsonPath('data.runs.0.status', 'succeeded');

        $this->assertStringNotContainsString((string) config('mercado_publico.ticket'), $response->getContent());
    }

    public function test_user_without_read_permission_is_forbidden(): void
    {
        $approver = $this->userWithTenantRole('tenant_approver');
        $this->actingAs($approver)->getJson('/api/v1/public-procurement/compra-agil')
            ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_catalogs_exclude_unused_oc_emitida_status(): void
    {
        $this->actingAs($this->reader)->getJson('/api/v1/public-procurement/catalogs')
            ->assertOk()->assertJsonCount(16, 'data.regions')->assertJsonCount(5, 'data.compra_agil_statuses');
    }

    public function test_opportunity_count_helper(): void
    {
        $this->seedOpportunities();
        $this->assertSame(3, PublicProcurementOpportunity::count());
    }
}
