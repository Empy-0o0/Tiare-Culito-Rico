<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SyncCompraAgilJob;
use App\Models\MercadoPublicoSyncRun;
use App\Models\MercadoPublicoSyncState;
use App\Services\Audit\AuditLogger;
use App\Services\MercadoPublico\MercadoPublicoConfig;
use App\Services\MercadoPublico\Sync\MercadoPublicoQuotaService;
use App\Services\MercadoPublico\Sync\MercadoPublicoSyncService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MercadoPublicoSyncController extends Controller
{
    public function status(MercadoPublicoQuotaService $quota, MercadoPublicoConfig $config): JsonResponse
    {
        $key = $config->syncMode() === 'changes' ? MercadoPublicoSyncService::STATE_KEY_COMPRA_AGIL : MercadoPublicoSyncService::STATE_KEY_PUBLICATIONS;
        $state = MercadoPublicoSyncState::query()->where('key', $key)->first();
        $runs = MercadoPublicoSyncRun::query()->latest('id')->limit(10)->get();

        return ApiResponse::ok([
            'integration' => [
                'enabled' => $config->enabled(),
                'configured' => $config->hasTicket(), // nunca el valor
                'scheduled_sync_enabled' => $config->syncEnabled(),
                'sync_interval_minutes' => $config->syncIntervalMinutes(),
            ],
            'compra_agil' => [
                'last_success_at' => $state?->last_success_finished_at?->toIso8601String(),
                'last_attempt_at' => $state?->last_attempt_at?->toIso8601String(),
                'last_status' => $state?->last_status,
                // TD-029: modo, hasta dónde se conoce y si quedó un recorrido por continuar.
                'mode' => $config->syncMode(),
                'known_until' => $state?->checkpoint['cursor'] ?? null,
                'pending_resume' => ! empty($state?->checkpoint['resume']),
                'manual_cooldown_minutes' => $config->manualCooldownMinutes(),
            ],
            'quota' => ['v2' => $quota->usageToday('v2'), 'v1' => $quota->usageToday('v1')],
            'runs' => $runs->map(fn (MercadoPublicoSyncRun $r) => [
                'public_id' => $r->public_id,
                'source_api' => $r->source_api,
                'trigger' => $r->trigger,
                'status' => $r->status,
                'started_at' => $r->started_at?->toIso8601String(),
                'finished_at' => $r->finished_at?->toIso8601String(),
                'pages_processed' => $r->pages_processed,
                'pages_total' => $r->pages_total,
                'requests_used' => $r->requests_used,
                'items_received' => $r->items_received,
                'items_created' => $r->items_created,
                'items_updated' => $r->items_updated,
                'items_unchanged' => $r->items_unchanged,
                'error_code' => $r->error_code,
                'error_summary' => $r->error_summary,
            ])->values(),
        ]);
    }

    /** Encola una sincronización incremental (requiere public_procurement.sync). */
    public function trigger(Request $request, AuditLogger $audit, MercadoPublicoConfig $config): JsonResponse
    {
        if (! $config->enabled() || ! $config->hasTicket()) {
            return ApiResponse::error('INTEGRATION_NOT_CONFIGURED', 'La integración con Mercado Público no está habilitada o configurada en el servidor.', 503);
        }

        // Espera mínima entre sincronizaciones manuales (TD-029): si se actualizó hace poco, no se gasta cuota.
        $cooldown = $config->manualCooldownMinutes();
        $recent = MercadoPublicoSyncRun::query()
            ->where('source_api', 'compra_agil_v2')
            ->whereIn('status', [MercadoPublicoSyncRun::STATUS_SUCCEEDED, MercadoPublicoSyncRun::STATUS_PARTIAL, MercadoPublicoSyncRun::STATUS_RUNNING])
            ->where('started_at', '>=', now()->subMinutes($cooldown))
            ->latest('id')
            ->first();
        if ($cooldown > 0 && $recent) {
            $minutes = max(1, (int) ceil($recent->started_at->diffInSeconds(now(), true) / 60));
            $wait = max(1, $cooldown - $minutes + 1);

            return ApiResponse::ok([
                'queued' => false,
                'skipped' => true,
                'message' => $recent->status === MercadoPublicoSyncRun::STATUS_RUNNING
                    ? 'Ya hay una sincronización en curso.'
                    : "Se sincronizó hace {$minutes} min. Para cuidar la cuota diaria, podrás volver a sincronizar en {$wait} min.",
            ]);
        }

        SyncCompraAgilJob::dispatch('api', $request->user()->getKey());
        $audit->log('public_procurement.sync.requested', metadata: ['source_api' => 'compra_agil_v2', 'mode' => $config->syncMode()]);

        return ApiResponse::ok(['queued' => true, 'message' => 'Buscando publicaciones nuevas en Mercado Público. Requiere un worker de cola activo (php artisan queue:work).'], status: 202);
    }
}
