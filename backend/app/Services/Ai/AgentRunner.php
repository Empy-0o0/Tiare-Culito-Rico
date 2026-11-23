<?php

namespace App\Services\Ai;

use App\Models\AiAgentRun;
use App\Models\User;
use App\Services\Ai\Agents\Agent;
use App\Services\Ai\Llm\LlmClient;
use App\Services\Ai\Llm\LlmException;
use App\Services\Ai\Llm\LlmRequest;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Ejecución controlada de agentes: carga el caso con alcance de empresa, calcula
 * hechos deterministas, llama al modelo si está habilitado y guarda todo
 * (versión de prompt, modelo, entrada, salida, tokens, latencia, usuario).
 * Si el modelo falla, la ejecución queda PARTIAL con los hechos igualmente útiles.
 */
final class AgentRunner
{
    public function __construct(private readonly LlmClient $llm, private readonly AuditLogger $audit) {}

    public function modelAvailable(): bool
    {
        return $this->llm->available();
    }

    /** Ejecuciones con modelo que quedan hoy para la empresa activa. */
    public function remainingToday(): int
    {
        $start = CarbonImmutable::now('America/Santiago')->startOfDay()->utc();
        $used = AiAgentRun::query()->where('mode', 'llm')->where('created_at', '>=', $start)->count();

        return max(0, (int) config('ai.daily_runs_per_tenant') - $used);
    }

    /**
     * @param  bool  $useModel  false = solo hechos deterministas.
     */
    public function run(Agent $agent, string $subjectRef, User $user, bool $useModel = true): AiAgentRun
    {
        $subject = $agent->loadSubject($subjectRef, $user);
        $snapshot = $subject['snapshot'];
        $facts = $agent->facts($snapshot);

        $withModel = $useModel && $this->llm->available();
        if ($withModel && $this->remainingToday() <= 0) {
            throw ValidationException::withMessages(['agent' => 'Tu empresa alcanzó el límite diario de análisis con IA. Puedes ejecutar el análisis sin modelo o intentar mañana.']);
        }

        $attrs = [
            'agent_id' => $agent->id(),
            'prompt_version' => $agent->promptVersion(),
            'subject_type' => $agent->subjectType(),
            'subject_ref' => $subjectRef,
            'subject_label' => $subject['label'],
            'mode' => $withModel ? 'llm' : 'deterministic',
            'status' => 'COMPLETED',
            'input_hash' => hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE)),
            'input_snapshot' => $snapshot,
            'facts' => $facts,
            'requested_by' => $user->getKey(),
            'review_status' => 'PENDING',
        ];

        if ($withModel) {
            $started = hrtime(true);
            try {
                $result = $this->llm->structured(new LlmRequest($agent->systemPrompt(), $agent->modelInput($snapshot, $facts), $agent->analysisSchema()));
                $attrs += [
                    'analysis' => $result->data,
                    'provider' => $this->llm->provider(),
                    'model' => $result->model,
                    'input_tokens' => $result->inputTokens,
                    'output_tokens' => $result->outputTokens,
                ];
            } catch (LlmException $e) {
                $attrs['status'] = 'PARTIAL';
                $attrs['provider'] = $this->llm->provider();
                $attrs['error_code'] = $e->errorCode;
                $attrs['error_message'] = mb_substr($e->getMessage(), 0, 500);
            }
            $attrs['latency_ms'] = (int) ((hrtime(true) - $started) / 1_000_000);
        }

        $run = AiAgentRun::create($attrs);
        $this->audit->log('agents.run', $user->getKey(), 'ai_agent_run', $run->public_id, [
            'agent' => $agent->id(), 'prompt_version' => $agent->promptVersion(), 'subject' => $agent->subjectType().':'.$subjectRef,
            'mode' => $run->mode, 'status' => $run->status, 'model' => $run->model,
        ]);

        return $run;
    }

    /** Revisión humana persistida (una vez). No cambia ningún dato de negocio. */
    public function review(AiAgentRun $run, string $verdict, ?string $comment, User $user): AiAgentRun
    {
        if ($run->review_status !== 'PENDING') {
            throw ValidationException::withMessages(['review' => 'Este análisis ya fue revisado.']);
        }
        if ($verdict === 'DISCARDED' && trim((string) $comment) === '') {
            throw ValidationException::withMessages(['comment' => 'Indica por qué descartas el análisis.']);
        }
        $run->update(['review_status' => $verdict, 'reviewed_by' => $user->getKey(), 'reviewed_at' => now(), 'review_comment' => $comment]);
        $this->audit->log('agents.reviewed', $user->getKey(), 'ai_agent_run', $run->public_id, ['verdict' => $verdict]);

        return $run;
    }
}
