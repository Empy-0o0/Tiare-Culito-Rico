<?php

namespace Tests\Feature\MercadoPublico;

use App\Models\MercadoPublicoSyncRun;
use App\Models\MercadoPublicoSyncState;
use App\Models\PublicProcurementOpportunity;
use App\Models\PublicProcurementRawPayload;
use App\Services\MercadoPublico\Sync\MercadoPublicoSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CompraAgilSyncTest extends TestCase
{
    use RefreshDatabase;

    private function fakeTwoPages(): void
    {
        Http::fake(function (Request $request) {
            $page = (int) ($request['numero_pagina'] ?? 1);

            return Http::response($this->fixture($page === 1 ? 'compra_agil_list_page1' : 'compra_agil_list_page2'));
        });
    }

    private function sync(): MercadoPublicoSyncService
    {
        return app(MercadoPublicoSyncService::class);
    }

    public function test_paginates_until_total_pages_and_persists(): void
    {
        $this->fakeTwoPages();

        $run = $this->sync()->syncCompraAgil();

        $this->assertSame(MercadoPublicoSyncRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(2, $run->pages_processed);
        $this->assertSame(2, $run->pages_total);
        $this->assertSame(2, $run->requests_used);
        $this->assertSame(3, $run->items_received);
        $this->assertSame(3, $run->items_created);
        Http::assertSentCount(2);
        $this->assertSame(3, PublicProcurementOpportunity::count());

        $first = PublicProcurementOpportunity::where('external_code', '9000001-11-COT26')->first();
        $this->assertSame('compra_agil', $first->source_type);
        $this->assertSame('compra_agil_v2', $first->source_api);
        $this->assertNotNull($first->last_fetched_at);
        $this->assertSame(1, $first->documents()->count());
        $this->assertSame(1, PublicProcurementRawPayload::where('opportunity_id', $first->id)->where('kind', 'list')->count());

        $third = PublicProcurementOpportunity::where('external_code', '9000003-33-COT26')->first();
        $this->assertNull($third->region_code, 'Región 99 no es válida y no se inventa');
    }

    public function test_first_run_uses_initial_window_and_ttl_parameter_only(): void
    {
        $this->fakeTwoPages();
        $this->sync()->syncCompraAgil();

        Http::assertSent(fn (Request $r) => (int) $r['ttl_cambio_ms'] === 24 * 3600 * 1000
            && ! isset($r['cambio_desde']) && (int) $r['tamano_pagina'] === 50
            && $r['ordenar_por'] === 'FechaUltimaModificacion');
    }

    public function test_sync_is_idempotent(): void
    {
        $this->fakeTwoPages();
        $this->sync()->syncCompraAgil();
        $second = $this->sync()->syncCompraAgil();

        $this->assertSame(3, PublicProcurementOpportunity::count());
        $this->assertSame(0, $second->items_created);
        $this->assertSame(3, $second->items_unchanged);
        $this->assertSame(3, PublicProcurementRawPayload::count());
    }

    public function test_incremental_window_uses_last_success_plus_overlap(): void
    {
        CarbonImmutable::setTestNow('2026-11-14 12:00:00');
        MercadoPublicoSyncState::create(['key' => 'compra_agil_v2', 'last_success_started_at' => '2026-11-14 11:30:00']);
        $this->fakeTwoPages();

        $this->sync()->syncCompraAgil();

        // 30 min desde el último éxito + 15 min de solapamiento.
        Http::assertSent(fn (Request $r) => (int) $r['ttl_cambio_ms'] === 45 * 60 * 1000);
        $this->assertSame('2026-11-14T12:00:00+00:00', MercadoPublicoSyncState::first()->last_success_started_at->toIso8601String());
        CarbonImmutable::setTestNow();
    }

    public function test_changed_record_is_updated(): void
    {
        $changed = $this->fixture('compra_agil_list_page1');
        $changed['payload']['items'][0]['estado'] = ['id_estado' => 3, 'codigo' => 'cerrada', 'glosa' => 'Cerrada'];
        $changed['payload']['items'][0]['fechas']['fecha_ultimo_cambio'] = '2026-11-16T11:05:00.000Z';
        $changed['payload']['paginacion']['total_paginas'] = 1;

        Http::fake(['*' => Http::sequence()
            ->push($this->fixture('compra_agil_list_page1'))
            ->push($this->fixture('compra_agil_list_page2'))
            ->push($changed)]);

        $this->sync()->syncCompraAgil();
        $run = $this->sync()->syncCompraAgil();

        $this->assertSame(1, $run->items_updated);
        $this->assertSame('cerrada', PublicProcurementOpportunity::where('external_code', '9000001-11-COT26')->value('status_code'));
    }

    public function test_quota_exhausted_mid_run_keeps_progress_and_does_not_advance_checkpoint(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->fixture('compra_agil_list_page1'))
            ->push(['success' => 'NOK', 'payload' => null, 'errors' => [['codigo' => '429']]], 429)]);

        $run = $this->sync()->syncCompraAgil();

        $this->assertSame(MercadoPublicoSyncRun::STATUS_QUOTA_EXHAUSTED, $run->status);
        $this->assertSame('MP_QUOTA_EXHAUSTED', $run->error_code);
        $this->assertSame(2, PublicProcurementOpportunity::count(), 'La página 1 queda persistida');
        $this->assertNull(MercadoPublicoSyncState::first()->last_success_started_at);
        Http::assertSentCount(2); // sin reintento tras 429
    }

    public function test_max_pages_produces_partial_run(): void
    {
        $this->fakeTwoPages();
        $run = $this->sync()->syncCompraAgil(maxPages: 1);

        $this->assertSame(MercadoPublicoSyncRun::STATUS_PARTIAL, $run->status);
        $this->assertSame('MAX_PAGES_REACHED', $run->error_code);
        $this->assertNull(MercadoPublicoSyncState::first()->last_success_started_at);
    }

    public function test_full_snapshot_omits_change_window_and_does_not_advance_marker(): void
    {
        $this->fakeTwoPages();
        $run = $this->sync()->syncCompraAgil(filters: ['statuses' => ['publicada']], fullSnapshot: true);

        $this->assertSame(MercadoPublicoSyncRun::STATUS_SUCCEEDED, $run->status);
        Http::assertSent(fn (Request $r) => ! isset($r['ttl_cambio_ms']) && $r['estado'] === 'publicada');
        $this->assertNull(MercadoPublicoSyncState::first()->last_success_started_at);
    }

    public function test_full_snapshot_can_resume_from_page(): void
    {
        $this->fakeTwoPages();
        $run = $this->sync()->syncCompraAgil(filters: ['statuses' => ['publicada']], fullSnapshot: true, startPage: 2);

        $this->assertSame(MercadoPublicoSyncRun::STATUS_SUCCEEDED, $run->status);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => (int) $r['numero_pagina'] === 2);
        $this->assertSame(1, PublicProcurementOpportunity::count());
    }

    public function test_incremental_sync_cannot_skip_pages(): void
    {
        Http::fake();
        $this->expectException(\InvalidArgumentException::class);
        $this->sync()->syncCompraAgil(startPage: 3);
    }

    public function test_full_snapshot_requires_filters(): void
    {
        Http::fake();
        $this->expectException(\InvalidArgumentException::class);
        $this->sync()->syncCompraAgil(fullSnapshot: true);
    }

    public function test_concurrent_run_is_skipped(): void
    {
        Http::fake();
        $lock = Cache::lock('mercado-publico:sync:compra-agil', 60);
        $lock->get();

        $run = $this->sync()->syncCompraAgil();

        $this->assertSame(MercadoPublicoSyncRun::STATUS_SKIPPED, $run->status);
        Http::assertNothingSent();
        $lock->release();
    }

    public function test_sync_run_never_stores_ticket(): void
    {
        $this->fakeTwoPages();
        $this->sync()->syncCompraAgil();

        $dump = json_encode([MercadoPublicoSyncRun::all()->toArray(), MercadoPublicoSyncState::all()->toArray()]);
        $this->assertStringNotContainsString((string) config('mercado_publico.ticket'), $dump);
    }
}
