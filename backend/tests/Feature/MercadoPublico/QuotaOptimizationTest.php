<?php

namespace Tests\Feature\MercadoPublico;

use App\Models\MercadoPublicoSyncRun;
use App\Models\MercadoPublicoSyncState;
use App\Models\PublicProcurementOpportunity;
use App\Services\MercadoPublico\CompraAgilDetailService;
use App\Services\MercadoPublico\Exceptions\MercadoPublicoException;
use App\Services\MercadoPublico\Sync\MercadoPublicoQuotaService;
use App\Services\MercadoPublico\Sync\MercadoPublicoSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TD-029: sincronización por publicaciones nuevas con reanudación, reserva de cuota para personas,
 * espera mínima de la sincronización manual y caché de fichas según estado.
 */
class QuotaOptimizationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> fechas de publicación (más reciente primero), formato "Y-m-d H:i" como la API */
    private array $feed = [];

    /** @var list<int> páginas pedidas */
    private array $requested = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mercado_publico.enabled' => true,
            'mercado_publico.ticket' => 'ticket-ficticio-de-prueba',
            'mercado_publico.v2.daily_budget' => 100,
            'mercado_publico.v2.interactive_reserve' => 30,
            'mercado_publico.sync.max_pages' => 3,
            'mercado_publico.sync.initial_window_hours' => 24,
            'mercado_publico.sync.overlap_minutes' => 0,
        ]);
        CarbonImmutable::setTestNow('2026-11-18 15:00:00');
        Http::fake(function (Request $r) {
            if (preg_match('#/v2/compra-agil/([^/?]+)#', $r->url(), $m)) {
                return Http::response(['success' => 'OK', 'payload' => $this->item($m[1], '2026-11-18 10:00', 'cerrada')]);
            }
            $page = (int) $r['numero_pagina'];
            $this->requested[] = $page;
            $chunks = array_chunk($this->feed, 50);
            $items = array_map(fn ($date, $i) => $this->item('9'.str_pad((string) (($page - 1) * 50 + $i), 6, '0', STR_PAD_LEFT).'-1-COT26', $date), $chunks[$page - 1] ?? [], array_keys($chunks[$page - 1] ?? []));

            return Http::response(['success' => 'OK', 'payload' => ['items' => $items, 'paginacion' => ['total_paginas' => max(1, count($chunks)), 'numero_pagina' => $page, 'tamano_pagina' => 50, 'total_resultados' => count($this->feed)]]]);
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function item(string $code, string $published, string $status = 'publicada'): array
    {
        return [
            'codigo' => $code,
            'nombre' => 'COMPRA FICTICIA '.$code,
            'estado' => ['codigo' => $status, 'glosa' => ucfirst($status)],
            'fechas' => ['fecha_publicacion' => $published, 'fecha_cierre' => '2026-11-25 15:00', 'fecha_ultimo_cambio' => '2026-11-18T10:00:00Z'],
            'institucion' => ['organismo_comprador' => 'ORGANISMO FICTICIO', 'rut' => '61.000.001-1', 'region' => 13],
        ];
    }

    /** n publicaciones, una por minuto hacia atrás desde $from */
    private function publications(int $n, string $from): array
    {
        $t = CarbonImmutable::parse($from);

        return array_map(fn ($i) => $t->subMinutes($i)->format('Y-m-d H:i'), range(0, $n - 1));
    }

    private function sync(): MercadoPublicoSyncRun
    {
        return app(MercadoPublicoSyncService::class)->syncNewPublications('scheduler');
    }

    public function test_stops_as_soon_as_it_reaches_known_publications(): void
    {
        // Primera vez: ventana inicial de 24 h. 120 publicaciones en las últimas 2 h + antiguas.
        $this->feed = array_merge($this->publications(120, '2026-11-18 14:59'), $this->publications(200, '2026-11-16 12:00'));
        $run = $this->sync();
        $this->assertSame('succeeded', $run->status);
        $this->assertSame([1, 2, 3], $this->requested, 'Se detiene en la página que alcanza publicaciones de hace más de 24 h');

        // Segunda vez, sin publicaciones nuevas: una sola solicitud.
        $this->requested = [];
        $run = $this->sync();
        $this->assertSame('succeeded', $run->status);
        $this->assertSame([1], $this->requested);
        $this->assertSame(1, $run->requests_used);

        // Con 60 nuevas: solo 2 páginas.
        $this->requested = [];
        $this->feed = array_merge($this->publications(60, '2026-11-18 16:00'), $this->feed);
        CarbonImmutable::setTestNow('2026-11-18 16:01:00');
        $this->sync();
        $this->assertSame([1, 2], $this->requested);
    }

    public function test_partial_run_resumes_instead_of_starting_over(): void
    {
        // 400 publicaciones en 24 h = 8 páginas; máximo 3 por ejecución.
        $this->feed = array_merge($this->publications(400, '2026-11-18 14:59'), $this->publications(100, '2026-11-16 12:00'));

        $this->assertSame('partial', $this->sync()->status);
        $this->assertSame([1, 2, 3], $this->requested);

        $this->requested = [];
        $this->assertSame('partial', $this->sync()->status);
        $this->assertSame([3, 4, 5], $this->requested, 'Continúa (una página antes por si la lista se corrió)');

        $this->requested = [];
        $this->assertSame('partial', $this->sync()->status);
        $this->assertSame([5, 6, 7], $this->requested);

        $this->requested = [];
        $this->assertSame('succeeded', $this->sync()->status);
        $this->assertSame([7, 8, 9], $this->requested);

        $state = MercadoPublicoSyncState::where('key', MercadoPublicoSyncService::STATE_KEY_PUBLICATIONS)->first();
        $this->assertNull($state->checkpoint['resume']);
        // Cursor = publicación más reciente vista (las fechas de la API se interpretan en hora de Chile).
        $this->assertTrue(CarbonImmutable::parse($state->checkpoint['cursor'])->equalTo(PublicProcurementOpportunity::max('published_at')));
        // 400 nuevas + 50 antiguas de la página que alcanzó lo conocido; la página 10 no se pide.
        $this->assertSame(450, PublicProcurementOpportunity::count());
    }

    public function test_background_sync_leaves_the_interactive_reserve(): void
    {
        // Ya se usaron 69 de 100: el automático puede usar hasta 70 (reserva de 30).
        DB::table('mercado_publico_api_usage')->insert(['api' => 'v2', 'usage_date' => '2026-11-18', 'request_count' => 69, 'error_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->feed = $this->publications(400, '2026-11-18 14:59');

        $run = app(MercadoPublicoQuotaService::class)->asBackground(fn () => $this->sync());
        $this->assertSame('quota_exhausted', $run->status);
        $this->assertSame([1], $this->requested, 'Solo 1 solicitud antes de llegar al tope automático');
        $this->assertStringContainsString('quedan para fichas', $run->error_summary);

        // Una persona todavía puede abrir una ficha (usa la reserva) y no quedó bloqueado el día.
        $result = app(CompraAgilDetailService::class)->get('1234-5-COT26');
        $this->assertSame('1234-5-COT26', $result['opportunity']->external_code);
        $this->assertNull(DB::table('mercado_publico_api_usage')->where('api', 'v2')->value('blocked_until'));

        // Con el próximo automático, retoma donde quedó.
        DB::table('mercado_publico_api_usage')->where('api', 'v2')->update(['request_count' => 0]);
        $this->requested = [];
        app(MercadoPublicoQuotaService::class)->asBackground(fn () => $this->sync());
        $this->assertSame(1, $this->requested[0]);
    }

    public function test_reserve_applies_only_to_background_processes(): void
    {
        DB::table('mercado_publico_api_usage')->insert(['api' => 'v2', 'usage_date' => '2026-11-18', 'request_count' => 70, 'error_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $quota = app(MercadoPublicoQuotaService::class);

        $quota->reserve('v2'); // persona: permitido
        $this->expectException(MercadoPublicoException::class);
        $quota->asBackground(fn () => $quota->reserve('v2'));
    }

    public function test_closed_details_are_cached_longer_than_open_ones(): void
    {
        $detail = app(CompraAgilDetailService::class);
        $detail->get('1234-5-COT26'); // la API la informa "cerrada"
        $calls = fn () => collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), '/v2/compra-agil/1234-5-COT26'))->count();
        $this->assertSame(1, $calls());

        CarbonImmutable::setTestNow('2026-11-18 20:00:00'); // 5 h después: sigue vigente (24 h)
        $detail->get('1234-5-COT26');
        $this->assertSame(1, $calls());

        // Una abierta se vuelve a pedir pasados 60 minutos.
        PublicProcurementOpportunity::where('external_code', '1234-5-COT26')->update(['status_code' => 'publicada', 'closes_at' => '2026-11-30 00:00:00']);
        $detail->get('1234-5-COT26');
        $this->assertSame(2, $calls());
    }

    public function test_manual_sync_has_a_cooldown(): void
    {
        $this->seedRoles();
        $analyst = $this->platformUser('public_procurement_analyst');
        $this->feed = $this->publications(10, '2026-11-18 14:59');
        $this->sync(); // sincronizado recién

        $this->actingAs($analyst)->postJson('/api/v1/public-procurement/sync')
            ->assertOk()
            ->assertJsonPath('data.skipped', true)
            ->assertJsonPath('data.message', fn ($m) => str_contains($m, 'Se sincronizó hace'));

        CarbonImmutable::setTestNow('2026-11-18 15:30:00');
        $this->travelTo(now()->addMinutes(30));
        $this->actingAs($analyst)->postJson('/api/v1/public-procurement/sync')->assertStatus(202);
    }
}
