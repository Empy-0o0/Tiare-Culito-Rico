<?php

namespace Tests\Feature\MercadoPublico;

use App\Models\PublicAlert;
use App\Models\PublicOpportunityMatch;
use App\Models\PublicProcurementOpportunity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Crm\CrmTestCase;

class SavedSearchesTest extends CrmTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests(); // el matching no consulta Mercado Público
        CarbonImmutable::setTestNow('2026-11-21 12:00:00');
        $this->opp('1001-1-COT26', 'Compra de detergente industrial', 8, '2026-11-25 15:00:00', ['Detergente 5 L']);
        $this->opp('1002-1-COT26', 'Resmas de papel carta', 13, '2026-11-22 03:00:00', ['Papel carta 500 hojas']); // cierra en 15 h
        $this->opp('1003-1-COT26', 'Detergente para cocina', 13, '2026-11-20 15:00:00', ['Detergente'], 'cerrada'); // cerrada
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function opp(string $code, string $title, int $region, string $closes, array $items, string $status = 'publicada'): void
    {
        $o = PublicProcurementOpportunity::create([
            'source_system' => 'mercado_publico', 'source_type' => 'compra_agil', 'source_api' => 'compra_agil_v2', 'source_api_version' => 'v2',
            'external_code' => $code, 'title' => $title, 'status_code' => $status, 'status_label' => ucfirst($status), 'region_code' => $region,
            'region_name' => $region === 8 ? 'Biobío' : 'Metropolitana de Santiago', 'buyer_name' => 'Organismo Ficticio',
            'published_at' => '2026-11-19 12:00:00', 'closes_at' => $closes, 'available_amount_clp' => 500000, 'last_fetched_at' => '2026-11-21 11:00:00',
        ]);
        foreach ($items as $i => $name) {
            $o->items()->create(['line_number' => $i + 1, 'name' => $name, 'quantity' => 10]);
        }
    }

    public function test_create_search_matches_open_opportunities_by_words(): void
    {
        $res = $this->actingAs($this->adminA)->postJson('/api/v1/public-procurement/saved-searches', [
            'name' => 'Aseo', 'criteria' => ['q' => 'detergente'],
        ])->assertCreated();
        $res->assertJsonPath('data.matches_count', 1)->assertJsonPath('data.new_count', 1)->assertJsonPath('meta.run.new', 1)
            ->assertJsonPath('data.criteria.open_only', true);

        $matches = $this->actingAs($this->adminA)->getJson('/api/v1/public-procurement/matches')->assertOk();
        $matches->assertJsonPath('data.0.opportunity.code', '1001-1-COT26')->assertJsonPath('data.0.reasons.words.0', 'detergente')
            ->assertJsonPath('data.0.opportunity.data_origin', 'official');

        // Sin "solo abiertas" entra también la cerrada.
        $id = $res->json('data.public_id');
        $this->actingAs($this->adminA)->patchJson("/api/v1/public-procurement/saved-searches/{$id}", ['criteria' => ['q' => 'detergente', 'open_only' => false]])
            ->assertOk()->assertJsonPath('data.matches_count', 2)->assertJsonPath('meta.run.new', 1);

        // Al crear no se generan alertas: la persona ya ve el resultado.
        $this->assertSame(0, PublicAlert::withoutTenantScope()->count());

        // Validaciones: al menos un criterio y nombre único por empresa.
        $this->actingAs($this->adminA)->postJson('/api/v1/public-procurement/saved-searches', ['name' => 'Vacía', 'criteria' => ['open_only' => true]])->assertStatus(422);
        $this->actingAs($this->adminA)->postJson('/api/v1/public-procurement/saved-searches', ['name' => 'Aseo', 'criteria' => ['q' => 'papel']])->assertStatus(422);
        $this->actingAs($this->adminB)->postJson('/api/v1/public-procurement/saved-searches', ['name' => 'Aseo', 'criteria' => ['q' => 'papel']])->assertCreated();
    }

    public function test_catalog_matching_and_region_filter(): void
    {
        // Sin catálogo no hay coincidencias por catálogo.
        $this->actingAs($this->adminA)->postJson('/api/v1/public-procurement/saved-searches', ['name' => 'Mi catálogo', 'criteria' => ['use_catalog' => true]])
            ->assertCreated()->assertJsonPath('meta.run.catalog_empty', true)->assertJsonPath('data.matches_count', 0);

        $this->actingAs($this->adminA)->postJson('/api/v1/products', ['name' => 'Papel carta', 'kind' => 'PRODUCT', 'unit_code' => 'EA'])->assertCreated();
        $id = $this->actingAs($this->adminA)->getJson('/api/v1/public-procurement/saved-searches')->json('data.0.public_id');
        $this->actingAs($this->adminA)->postJson("/api/v1/public-procurement/saved-searches/{$id}/run")->assertOk()
            ->assertJsonPath('data.matches_count', 1);
        $this->actingAs($this->adminA)->getJson('/api/v1/public-procurement/matches')
            ->assertJsonPath('data.0.opportunity.code', '1002-1-COT26')
            ->assertJsonPath('data.0.reasons.catalog.0.catalog.0', 'Papel carta');

        $this->actingAs($this->adminA)->postJson('/api/v1/public-procurement/saved-searches', ['name' => 'Biobío', 'criteria' => ['regions' => [8]]])
            ->assertCreated()->assertJsonPath('data.matches_count', 1)->assertJsonPath('data.regions.0.name', 'Biobío');
        $this->actingAs($this->adminA)->postJson('/api/v1/public-procurement/saved-searches', ['name' => 'Mala', 'criteria' => ['regions' => [99]]])->assertStatus(422);
    }

    public function test_scheduled_matching_creates_alerts_once_and_isolation(): void
    {
        $this->actingAs($this->adminA)->postJson('/api/v1/public-procurement/saved-searches', ['name' => 'Papel', 'criteria' => ['q' => 'papel']])->assertCreated();
        $this->actingAs($this->adminB)->postJson('/api/v1/public-procurement/saved-searches', ['name' => 'Detergente B', 'criteria' => ['q' => 'detergente']])->assertCreated();

        // Llega una oportunidad nueva por la sincronización.
        $this->opp('1004-1-COT26', 'Papel higiénico institucional', 13, '2026-11-28 15:00:00', ['Papel higiénico']);
        $this->artisan('public-procurement:match')->assertSuccessful();

        $alertsA = $this->actingAs($this->adminA)->getJson('/api/v1/public-procurement/alerts')->assertOk();
        $types = collect($alertsA->json('data'))->pluck('type.code')->sort()->values()->all();
        $this->assertSame(['CLOSING_SOON', 'NEW_MATCHES'], $types); // 1004 nueva; 1002 cierra en 15 h
        $new = collect($alertsA->json('data'))->firstWhere('type.code', 'NEW_MATCHES');
        $this->assertSame(['1004-1-COT26'], $new['codes']);
        $alertsA->assertJsonPath('meta.unread_count', 2);

        // Repetir no duplica alertas.
        $this->artisan('public-procurement:match')->assertSuccessful();
        $this->assertSame(2, PublicAlert::withoutTenantScope()->where('tenant_id', $this->tenantA->id)->count());

        // La empresa B solo ve lo suyo (su detergente no cierra en 24 h ni es nuevo).
        $this->actingAs($this->adminB)->getJson('/api/v1/public-procurement/alerts')->assertJsonPath('meta.unread_count', 0);
        $this->assertSame(['1001-1-COT26'], collect($this->actingAs($this->adminB)->getJson('/api/v1/public-procurement/matches')->json('data'))->pluck('opportunity.code')->all());

        // Lectura por usuario.
        $this->actingAs($this->adminA)->postJson('/api/v1/public-procurement/alerts/read', [])->assertOk()->assertJsonPath('data.marked', 2);
        $this->actingAs($this->adminA)->getJson('/api/v1/public-procurement/alerts')->assertJsonPath('meta.unread_count', 0)->assertJsonPath('data.0.read', true);
        $otherUser = $this->userWithTenantRole('tenant_read_only', $this->tenantA);
        $this->actingAs($otherUser)->getJson('/api/v1/public-procurement/alerts')->assertJsonPath('meta.unread_count', 2);

        // Descartar una coincidencia la saca del listado por defecto; la empresa B no puede tocarla.
        $match = PublicOpportunityMatch::withoutTenantScope()->where('tenant_id', $this->tenantA->id)->first();
        $this->actingAs($this->adminB)->patchJson("/api/v1/public-procurement/matches/{$match->public_id}", ['status' => 'DISMISSED'])->assertNotFound();
        $this->actingAs($this->adminA)->patchJson("/api/v1/public-procurement/matches/{$match->public_id}", ['status' => 'DISMISSED'])->assertOk();
        $this->assertCount(1, $this->actingAs($this->adminA)->getJson('/api/v1/public-procurement/matches')->json('data'));
        $this->actingAs($this->adminA)->getJson('/api/v1/public-procurement/matches?status=DISMISSED')->assertJsonPath('meta.pagination.total', 1);
    }
}
