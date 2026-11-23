<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiAgentRun;
use App\Models\User;
use App\Services\Ai\AgentRunner;
use App\Services\Ai\Agents\Agent;
use App\Services\Ai\Agents\AgentRegistry;
use App\Services\Ai\Agents\AgentSubjectException;
use App\Support\ApiResponse;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Agentes IA (CLAUDE.md §10). Lectura: agents.read; ejecutar: agents.run más los
 * permisos del caso; revisar: agents.review. Ningún endpoint de agentes escribe
 * datos de negocio.
 */
class AgentController extends Controller
{
    public function __construct(private readonly AgentRegistry $registry, private readonly AgentRunner $runner, private readonly CurrentTenant $tenant) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $available = $this->runner->modelAvailable();

        return ApiResponse::ok([
            'model' => [
                'available' => $available,
                'provider' => $available ? config('ai.provider') : null,
                'name' => $available ? config('ai.anthropic.model') : null,
                'daily_limit' => (int) config('ai.daily_runs_per_tenant'),
                'remaining_today' => $this->runner->remainingToday(),
            ],
            'agents' => collect($this->registry->all())->map(fn (Agent $a) => $a->describe() + ['can_run' => $this->canRun($user, $a)])->values(),
        ]);
    }

    public function runs(Request $request): JsonResponse
    {
        $v = $request->validate([
            'agent' => ['nullable', Rule::in(array_keys($this->registry->all()))],
            'subject_type' => ['nullable', 'string', 'max:40'],
            'subject_ref' => ['nullable', 'string', 'max:64'],
            'review_status' => ['nullable', Rule::in(['PENDING', 'USEFUL', 'DISCARDED'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $page = AiAgentRun::query()
            ->with(['requester:id,name', 'reviewer:id,name'])
            ->when($v['agent'] ?? null, fn ($q, $a) => $q->where('agent_id', $a))
            ->when($v['subject_type'] ?? null, fn ($q, $t) => $q->where('subject_type', $t))
            ->when($v['subject_ref'] ?? null, fn ($q, $r) => $q->where('subject_ref', $r))
            ->when($v['review_status'] ?? null, fn ($q, $s) => $q->where('review_status', $s))
            ->latest('id')
            ->paginate((int) ($v['per_page'] ?? 20));

        return ApiResponse::paginated($page, collect($page->items())->map(fn ($r) => $this->summary($r))->values());
    }

    public function show(AiAgentRun $run): JsonResponse
    {
        $run->loadMissing(['requester:id,name', 'reviewer:id,name']);

        return ApiResponse::ok($this->detail($run));
    }

    public function run(Request $request, string $agentId): JsonResponse
    {
        $agent = $this->registry->find($agentId);
        if (! $agent) {
            return ApiResponse::error('NOT_FOUND', 'Ese agente no existe.', 404);
        }
        $user = $request->user();
        if (! $this->canRun($user, $agent)) {
            return ApiResponse::error('FORBIDDEN', 'No tienes permiso para ejecutar este agente.', 403);
        }
        $v = $request->validate([
            'subject_ref' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[0-9A-Za-z\-]+$/'],
            'use_model' => ['sometimes', 'boolean'],
        ]);

        // El análisis con modelo puede tardar; no cortar la solicitud antes del timeout del cliente HTTP.
        @set_time_limit((int) config('ai.anthropic.timeout') + 30);

        try {
            $run = $this->runner->run($agent, $v['subject_ref'], $user, $request->boolean('use_model', true));
        } catch (AgentSubjectException $e) {
            return ApiResponse::error($e->status === 404 ? 'NOT_FOUND' : 'UNPROCESSABLE', $e->getMessage(), $e->status);
        }
        $run->load(['requester:id,name']);

        return ApiResponse::ok($this->detail($run), status: 201);
    }

    public function review(Request $request, AiAgentRun $run): JsonResponse
    {
        $v = $request->validate([
            'verdict' => ['required', Rule::in(['USEFUL', 'DISCARDED'])],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        $run = $this->runner->review($run, $v['verdict'], $v['comment'] ?? null, $request->user());
        $run->load(['requester:id,name', 'reviewer:id,name']);

        return ApiResponse::ok($this->detail($run));
    }

    private function canRun(User $user, Agent $agent): bool
    {
        $tenant = $this->tenant->get();
        foreach (['agents.run', ...$agent->requiredPermissions()] as $permission) {
            if (! $user->hasPermission($permission, $tenant)) {
                return false;
            }
        }

        return true;
    }

    private function summary(AiAgentRun $r): array
    {
        $agent = $this->registry->find($r->agent_id);

        return [
            'public_id' => $r->public_id,
            'agent' => ['id' => $r->agent_id, 'name' => $agent?->name() ?? $r->agent_id, 'prompt_version' => $r->prompt_version],
            'subject' => ['type' => $r->subject_type, 'ref' => $r->subject_ref, 'label' => $r->subject_label],
            'mode' => $r->mode,
            'status' => $r->status,
            'model' => $r->model,
            'review_status' => $r->review_status,
            'requested_by' => $r->requester?->name,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    private function detail(AiAgentRun $r): array
    {
        return $this->summary($r) + [
            'facts' => $r->facts,
            'analysis' => $r->analysis,
            'data_origin' => ['facts' => 'calculated', 'analysis' => $r->analysis ? 'ai' : null],
            'usage' => ['input_tokens' => $r->input_tokens, 'output_tokens' => $r->output_tokens, 'latency_ms' => $r->latency_ms, 'provider' => $r->provider],
            'error' => $r->error_code ? ['code' => $r->error_code, 'message' => $r->error_message] : null,
            'input_hash' => $r->input_hash,
            'review' => [
                'status' => $r->review_status,
                'by' => $r->reviewer?->name,
                'at' => $r->reviewed_at?->toIso8601String(),
                'comment' => $r->review_comment,
            ],
            'disclaimer' => 'Análisis de apoyo. No reemplaza la revisión de una persona ni constituye una decisión de compra, adjudicación o admisibilidad.',
        ];
    }
}
