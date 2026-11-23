<?php

namespace Tests\Feature\MercadoPublico;

use App\Models\PublicAlert;
use App\Models\PublicProcurementOpportunity;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Crm\CrmTestCase;

/** Búsquedas guardadas y alertas sobre licitaciones (API V1). */
class TenderAlertsTest extends CrmTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-21 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function tender(string $code, string $title, string $status = '5', string $closes = '2026-11-28 18:00:00'): PublicProcurementOpportunity
    {
        return PublicProcurementOpportunity::create([
            'source_system' => 'mercado_publico', 'source_type' => 'licitacion', 'source_api' => 'licitaciones_v1', 'source_api_version' => 'v1',
            'external_code' => $code, 'title' => $title, 'status_code' => $status, 'status_label' => $status === '5' ? 'Publicada' : 'Cerrada',
            'closes_at' => $closes, 'last_fetched_at' => '2026-11-21 11:00:00',
        ]);
    }

    /** Respuesta "activas" con la estructura real del listado V1. */
    private function activeList(array $rows): array
    {
        return ['Cantidad' => count($rows), 'FechaCreacion' => '2026-11-21T09:00:00', 'Version' => 'v1', 'Listado' => array_map(fn ($r) => [
            'CodigoExterno' => $r[0], 'Nombre' => $r[1], 'CodigoEstado' => 5, 'FechaCierre' => '2026-11-28T15:00:00',
        ], $rows)];
    }

    /** Detalle con la estructura real (fixture anonimizado) ajustado al caso. */
    private function detail(string $code, string $title): array
    {
        $json = $this->fixture('real_licitacion_detail');
        $json['Listado'][0]['CodigoExterno'] = $code;
        $json['Listado'][0]['Nombre'] = $title;
        $json['Listado'][0]['CodigoEstado'] = 5;
        $json['Listado'][0]['Estado'] = 'Publicada';
        $json['Listado'][0]['Fechas']['FechaCierre'] = '2026-11-28T15:00:00';

        return $json;
    }

    public function test_searches_only_include_tenders_when_requested(): void
    {
        $this->tender('1000-1-LE26', 'Servicio de aseo con detergente industrial');
        $this->tender('1000-2-LE26', 'Detergente para cocina', '6', '2026-11-20 18:00:00'); // cerrada

        $this->actingAs($this->adminA)->postJson('/api/v1/public-procurement/saved-searches', ['name' => 'Solo CA', 'criteria' => ['q' => 'detergente']])
            ->assertCreated()->assertJsonPath('data.matches_count', 0)->assertJsonPath('data.criteria.source_types', ['compra_agil']);

        $this->actingAs($this->adminA)->postJson('/api/v1/public-procurement/saved-searches', [
            'name' => 'Licitaciones', 'criteria' => ['q' => 'detergente', 'source_types' => ['compra_agil', 'licitacion']],
        ])->assertCreated()->assertJsonPath('data.matches_count', 1);

        $this->actingAs($this->adminA)->getJson('/api/v1/public-procurement/matches')
            ->assertJsonPath('data.0.opportunity.code', '1000-1-LE26')
            ->assertJsonPath('data.0.opportunity.source_type', 'licitacion')
            ->assertJsonPath('data.0.opportunity.has_detail', false);

        $this->actingAs($this->adminA)->postJson('/api/v1/public-procurement/saved-searches', ['name' => 'Mal', 'criteria' => ['q' => 'x', 'source_types' => ['orden_compra']]])->assertStatus(422);
        $this->actingAs($this->adminA)->postJson('/api/v1/public-procurement/saved-searches', ['name' => 'Vacío', 'criteria' => ['q' => 'x', 'source_types' => []]])->assertStatus(422);
    }

    public function test_watch_skips_without_tender_searches_and_spends_no_quota(): void
    {
        Http::fake();
        $this->actingAs($this->adminA)->postJson('/api/v1/public-procurement/saved-searches', ['name' => 'CA', 'criteria' => ['q' => 'papel']])->assertCreated();
        $this->artisan('public-procurement:watch-tenders')->expectsOutputToContain('Ninguna búsqueda activa incluye licitaciones')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_watch_lists_active_tenders_enriches_candidates_and_alerts(): void
    {
        $this->actingAs($this->adminA)->postJson('/api/v1/public-procurement/saved-searches', [
            'name' => 'Desbroce', 'criteria' => ['q' => 'tractor', 'regions' => [10], 'source_types' => ['licitacion']],
        ])->assertCreated()->assertJsonPath('data.matches_count', 0);

        Http::fake(function (Request $r) {
            if (($r['estado'] ?? null) === 'activas') {
                return Http::response($this->activeList([['2000-1-LE26', 'Arriendo tractor desbrozador'], ['2000-2-LE26', 'Compra de resmas de papel']]));
            }

            return Http::response($this->detail((string) $r['codigo'], 'Arriendo tractor desbrozador'));
        });

        $this->artisan('public-procurement:watch-tenders')->expectsOutputToContain('Detalles consultados: 1')->assertSuccessful();

        // Solo se pidió el detalle de la candidata (el nombre contiene "tractor"); el ticket va como parámetro V1.
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => ($r['codigo'] ?? null) === '2000-1-LE26' && $r['ticket'] === '00000000-TEST-TICKET-0000-000000000000');
        Http::assertNotSent(fn (Request $r) => ($r['codigo'] ?? null) === '2000-2-LE26');

        $t = PublicProcurementOpportunity::query()->where('external_code', '2000-1-LE26')->first();
        $this->assertSame(10, $t->region_code); // región disponible tras el detalle
        $this->assertNotNull($t->detail_fetched_at);

        // El matching programado la encuentra (por palabra y región) y avisa una sola vez.
        $this->artisan('public-procurement:match')->assertSuccessful();
        $this->artisan('public-procurement:match')->assertSuccessful();
        $alerts = PublicAlert::withoutTenantScope()->where('tenant_id', $this->tenantA->id)->get();
        $this->assertCount(1, $alerts);
        $this->assertSame(['2000-1-LE26'], $alerts[0]->payload['codes']);

        // Segunda vigilancia: la licitación ya tiene detalle, no se vuelve a pedir.
        $this->artisan('public-procurement:watch-tenders')->expectsOutputToContain('Detalles consultados: 0')->assertSuccessful();
        Http::assertSentCount(3);
    }

    public function test_closing_soon_alert_for_open_tender(): void
    {
        $this->tender('3000-1-LE26', 'Servicio de aseo y detergente', '5', '2026-11-22 03:00:00'); // cierra en 15 h
        $this->actingAs($this->adminA)->postJson('/api/v1/public-procurement/saved-searches', [
            'name' => 'Aseo', 'criteria' => ['q' => 'aseo', 'source_types' => ['licitacion']],
        ])->assertCreated();

        $this->artisan('public-procurement:match')->assertSuccessful();
        $this->actingAs($this->adminA)->getJson('/api/v1/public-procurement/alerts')
            ->assertJsonPath('data.0.type.code', 'CLOSING_SOON')
            ->assertJsonPath('data.0.source_type', 'licitacion')
            ->assertJsonPath('data.0.codes.0', '3000-1-LE26');
    }
}
