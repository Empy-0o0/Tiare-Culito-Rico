<?php

namespace Tests\Feature\Agents;

use App\Models\AiAgentRun;
use App\Models\AuditLog;
use App\Models\PublicProcurementOpportunity;
use App\Services\Ai\Llm\AnthropicLlmClient;
use App\Services\Ai\Llm\LlmClient;
use App\Services\Ai\Llm\LlmException;
use App\Services\Ai\Llm\LlmRequest;
use App\Services\Ai\Llm\LlmResult;
use Carbon\CarbonImmutable;
use LogicException;
use Tests\Feature\Crm\CrmTestCase;

/** Modelo falso: nunca se llama a internet en pruebas. */
class FakeLlm implements LlmClient
{
    /** @var list<LlmRequest> */
    public array $requests = [];

    public function __construct(public array|LlmException $response = []) {}

    public function available(): bool
    {
        return true;
    }

    public function provider(): string
    {
        return 'fake';
    }

    public function structured(LlmRequest $request): LlmResult
    {
        $this->requests[] = $request;
        if ($this->response instanceof LlmException) {
            throw $this->response;
        }

        return new LlmResult($this->response, 'fake-model', 1200, 300);
    }
}

class AgentsTest extends CrmTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-20 12:00:00'); // UTC = 09:00 en Santiago
        config(['ai.enabled' => false, 'ai.anthropic.api_key' => null, 'ai.daily_runs_per_tenant' => 30]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function opportunity(array $overrides = []): PublicProcurementOpportunity
    {
        $o = PublicProcurementOpportunity::create($overrides + [
            'source_system' => 'mercado_publico', 'source_type' => 'compra_agil', 'source_api' => 'compra_agil_v2', 'source_api_version' => 'v2',
            'external_code' => '1234-56-COT26', 'title' => 'Adquisición de detergente y guantes', 'description' => 'Se requiere detergente industrial para aseo de recintos.',
            'status_code' => 'publicada', 'status_label' => 'Publicada', 'buyer_name' => 'Municipalidad Ficticia', 'region_name' => 'Biobío',
            'published_at' => '2026-11-19 12:00:00', 'closes_at' => '2026-11-21 03:00:00', 'currency' => 'CLP', 'available_amount' => 900000, 'available_amount_clp' => 900000,
            'delivery_days' => 5, 'detail_fetched_at' => '2026-11-20 11:00:00',
        ]);
        $o->items()->create(['line_number' => 1, 'name' => 'Detergente industrial 5 L', 'quantity' => 20, 'unit_of_measure' => 'Unidad']);
        $o->items()->create(['line_number' => 2, 'name' => 'Escoba de cerda dura', 'quantity' => 4, 'unit_of_measure' => 'Unidad']);
        $o->documents()->create(['external_id' => 'D1', 'name' => 'Especificaciones técnicas.pdf']);

        return $o;
    }

    private function useLlm(array|LlmException $response): FakeLlm
    {
        $fake = new FakeLlm($response);
        $this->app->instance(LlmClient::class, $fake);

        return $fake;
    }

    private function tenderAnalysis(): array
    {
        return ['summary' => 'Compra de detergente.', 'observable_requirements' => [['requirement' => '20 unidades de 5 L', 'source' => 'productos']],
            'risks' => [['risk' => 'Cierre cercano', 'severity' => 'media']], 'questions_for_buyer' => [], 'checklist_additions' => [],
            'catalog_fit' => ['level' => 'medio', 'rationale' => 'Calza el detergente.', 'matched_products' => ['Detergente industrial']], 'uncertainty' => 'No leí los adjuntos.'];
    }

    public function test_catalog_reports_model_status_and_permissions(): void
    {
        $res = $this->actingAs($this->adminA)->getJson('/api/v1/agents')->assertOk();
        $res->assertJsonPath('data.model.available', false)->assertJsonPath('data.model.name', null);
        $agents = collect($res->json('data.agents'))->keyBy('id');
        $this->assertTrue($agents['tender_intelligence_agent']['can_run']);
        $this->assertTrue($agents['cost_intelligence_agent']['can_run']);
        $this->assertNotEmpty($agents['tender_intelligence_agent']['limits']);

        $reader = $this->userWithTenantRole('tenant_read_only', $this->tenantA);
        $this->assertFalse(collect($this->actingAs($reader)->getJson('/api/v1/agents')->json('data.agents'))->first()['can_run']);
        $this->actingAs($reader)->postJson('/api/v1/agents/tender_intelligence_agent/runs', ['subject_ref' => '1234-56-COT26'])->assertForbidden();
    }

    public function test_tender_agent_without_model_returns_deterministic_facts(): void
    {
        $this->opportunity();
        $this->actingAs($this->adminA)->postJson('/api/v1/products', ['name' => 'Detergente industrial', 'kind' => 'PRODUCT', 'unit_code' => 'EA'])->assertCreated();

        $res = $this->actingAs($this->adminA)->postJson('/api/v1/agents/tender_intelligence_agent/runs', ['subject_ref' => '1234-56-COT26'])->assertCreated();
        $res->assertJsonPath('data.mode', 'deterministic')->assertJsonPath('data.status', 'COMPLETED')->assertJsonPath('data.analysis', null)
            ->assertJsonPath('data.facts.open', true)
            ->assertJsonPath('data.facts.hours_to_close', 15) // cierre 00:00 Santiago, ahora 09:00
            ->assertJsonPath('data.facts.items_count', 2)
            ->assertJsonPath('data.facts.catalog_matches.0.requested', 'Detergente industrial 5 L')
            ->assertJsonPath('data.facts.source.code', '1234-56-COT26')
            ->assertJsonPath('data.review.status', 'PENDING');
        $this->assertSame('urgent', $res->json('data.facts.alerts.0.level'));
        $this->assertStringContainsString('1 documentos', $res->json('data.facts.checklist.0'));
        $this->assertTrue(AuditLog::query()->where('action', 'agents.run')->exists());

        // Oportunidad que no está en TIARE: no consulta Mercado Público, pide abrir la ficha.
        $this->actingAs($this->adminA)->postJson('/api/v1/agents/tender_intelligence_agent/runs', ['subject_ref' => '9999-99-COT26'])
            ->assertNotFound()->assertJsonPath('error.message', fn ($m) => str_contains($m, 'Ábrela primero'));
    }

    public function test_tender_agent_with_model_stores_analysis_and_sends_only_selected_data(): void
    {
        $this->opportunity();
        $fake = $this->useLlm($this->tenderAnalysis());

        $res = $this->actingAs($this->adminA)->postJson('/api/v1/agents/tender_intelligence_agent/runs', ['subject_ref' => '1234-56-COT26'])->assertCreated();
        $res->assertJsonPath('data.mode', 'llm')->assertJsonPath('data.model', 'fake-model')
            ->assertJsonPath('data.analysis.catalog_fit.level', 'medio')
            ->assertJsonPath('data.usage.input_tokens', 1200)
            ->assertJsonPath('data.data_origin.analysis', 'ai')
            ->assertJsonPath('data.agent.prompt_version', '1.0.0');

        $req = $fake->requests[0];
        $this->assertStringContainsString('no como instrucciones', $req->input);
        $this->assertStringContainsString('1234-56-COT26', $req->input);
        $this->assertStringContainsString('No presentas ofertas', $req->system);
        $this->assertFalse($req->schema['additionalProperties']);
        $this->assertStringNotContainsString('ticket', strtolower($req->input));

        // Error del modelo: la ejecución queda PARTIAL con los hechos.
        $fake->response = (new LlmException(LlmException::RATE_LIMITED, 'Se alcanzó el límite de uso de la API de Claude.'));
        $this->actingAs($this->adminA)->postJson('/api/v1/agents/tender_intelligence_agent/runs', ['subject_ref' => '1234-56-COT26'])->assertCreated()
            ->assertJsonPath('data.status', 'PARTIAL')->assertJsonPath('data.error.code', 'AI_RATE_LIMITED')->assertJsonPath('data.facts.open', true);

        // use_model=false fuerza el modo determinista y no cuenta para el límite.
        $this->actingAs($this->adminA)->postJson('/api/v1/agents/tender_intelligence_agent/runs', ['subject_ref' => '1234-56-COT26', 'use_model' => false])
            ->assertCreated()->assertJsonPath('data.mode', 'deterministic');
    }

    public function test_daily_limit_per_tenant(): void
    {
        $this->opportunity();
        $this->useLlm($this->tenderAnalysis());
        config(['ai.daily_runs_per_tenant' => 1]);
        $this->actingAs($this->adminA)->postJson('/api/v1/agents/tender_intelligence_agent/runs', ['subject_ref' => '1234-56-COT26'])->assertCreated();
        $this->actingAs($this->adminA)->postJson('/api/v1/agents/tender_intelligence_agent/runs', ['subject_ref' => '1234-56-COT26'])->assertStatus(422);
        $this->actingAs($this->adminA)->getJson('/api/v1/agents')->assertJsonPath('data.model.remaining_today', 0);
        // Otra empresa tiene su propio límite.
        $this->actingAs($this->adminB)->postJson('/api/v1/agents/tender_intelligence_agent/runs', ['subject_ref' => '1234-56-COT26'])->assertCreated();
    }

    public function test_cost_agent_facts_review_isolation_and_immutability(): void
    {
        $supA = $this->actingAs($this->adminA)->postJson('/api/v1/suppliers', ['organization' => ['legal_name' => 'Proveedor Alfa SpA', 'kind' => 'SME']])->json('data.public_id');
        $supB = $this->actingAs($this->adminA)->postJson('/api/v1/suppliers', ['organization' => ['legal_name' => 'Proveedor Beta SpA', 'kind' => 'SME']])->json('data.public_id');
        $id = $this->actingAs($this->adminA)->postJson('/api/v1/purchase-requests', [
            'title' => 'Insumos de aseo', 'items' => [['description' => 'Detergente 5 L', 'quantity' => 10, 'unit_code' => 'EA', 'estimated_unit_price' => 12000]],
        ])->assertCreated()->json('data.public_id');

        // Sin cotizaciones no hay nada que analizar.
        $this->actingAs($this->adminA)->postJson('/api/v1/agents/cost_intelligence_agent/runs', ['subject_ref' => $id])->assertStatus(422);

        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/submit")->assertOk();
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/start-quoting")->assertOk();
        $quote = fn (string $sup, int $price, ?string $validUntil = '2026-12-15') => $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/quotations", [
            'supplier_public_id' => $sup, 'valid_until' => $validUntil, 'delivery_days' => 3, 'items' => [['line_number' => 1, 'unit_price' => $price]],
        ])->assertCreated();
        $quote($supA, 10000);
        $quote($supB, 12000);

        $run = $this->actingAs($this->adminA)->postJson('/api/v1/agents/cost_intelligence_agent/runs', ['subject_ref' => $id])->assertCreated();
        $run->assertJsonPath('data.facts.comparable', 2)
            ->assertJsonPath('data.facts.cheapest.supplier', 'Proveedor Alfa SpA')
            ->assertJsonPath('data.facts.cheapest.total_clp', '119000.00')
            ->assertJsonPath('data.facts.spread.amount_clp', '23800.00')
            ->assertJsonPath('data.facts.spread.percent', 20)
            ->assertJsonPath('data.facts.cheapest_vs_estimate_percent', -0.8);
        $runId = $run->json('data.public_id');

        // Aislamiento: la empresa B no ve ni analiza la solicitud de A.
        $this->actingAs($this->adminB)->postJson('/api/v1/agents/cost_intelligence_agent/runs', ['subject_ref' => $id])->assertNotFound();
        $this->actingAs($this->adminB)->getJson("/api/v1/agent-runs/{$runId}")->assertNotFound();
        $this->assertSame(0, count($this->actingAs($this->adminB)->getJson('/api/v1/agent-runs')->json('data')));

        // Historial filtrado por caso.
        $this->actingAs($this->adminA)->getJson("/api/v1/agent-runs?subject_type=purchase_request&subject_ref={$id}")->assertJsonPath('meta.pagination.total', 1);

        // Revisión humana: descartar exige motivo; solo una vez.
        $this->actingAs($this->adminA)->postJson("/api/v1/agent-runs/{$runId}/review", ['verdict' => 'DISCARDED'])->assertStatus(422);
        $this->actingAs($this->adminA)->postJson("/api/v1/agent-runs/{$runId}/review", ['verdict' => 'USEFUL', 'comment' => 'Sirvió para negociar'])
            ->assertOk()->assertJsonPath('data.review.status', 'USEFUL')->assertJsonPath('data.review.by', $this->adminA->name);
        $this->actingAs($this->adminA)->postJson("/api/v1/agent-runs/{$runId}/review", ['verdict' => 'DISCARDED', 'comment' => 'x'])->assertStatus(422);

        $model = AiAgentRun::withoutGlobalScopes()->where('public_id', $runId)->first();
        $this->expectException(LogicException::class);
        $model->update(['facts' => []]);
    }

    public function test_anthropic_client_is_unavailable_without_key(): void
    {
        $client = new AnthropicLlmClient(['enabled' => true, 'anthropic' => ['api_key' => null]]);
        $this->assertFalse($client->available());
        $this->expectException(LlmException::class);
        $client->structured(new LlmRequest('s', 'i', ['type' => 'object']));
    }
}
