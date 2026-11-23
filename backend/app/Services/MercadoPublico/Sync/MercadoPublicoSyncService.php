<?php

namespace App\Services\MercadoPublico\Sync;

use App\Models\MercadoPublicoSyncRun;
use App\Models\MercadoPublicoSyncState;
use App\Services\MercadoPublico\Adapters\CompraAgilV2Adapter;
use App\Services\MercadoPublico\DTO\CompraAgilSearchQuery;
use App\Services\MercadoPublico\Exceptions\MercadoPublicoException;
use App\Services\MercadoPublico\MercadoPublicoConfig;
use App\Services\MercadoPublico\Persistence\CompraAgilRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sincronización incremental de Compra Ágil V2.
 *
 * Ventana: ttl_cambio_ms = (ahora − inicio del último éxito) + solapamiento. Se usa ventana
 * relativa en vez de cambio_desde/hasta por la ambigüedad de zona horaria (TD-005).
 * Recorre páginas de 50 hasta total_paginas (o max_pages), con upsert idempotente y
 * checkpoint por página. Una ejecución a la vez (lock).
 */
class MercadoPublicoSyncService
{
    public const STATE_KEY_COMPRA_AGIL = 'compra_agil_v2';

    public const STATE_KEY_PUBLICATIONS = 'compra_agil_v2_publications';

    public function __construct(
        private readonly CompraAgilV2Adapter $adapter,
        private readonly CompraAgilRepository $repository,
        private readonly MercadoPublicoConfig $config,
    ) {}

    /**
     * @param  array{regions?: list<int>, statuses?: list<string>}  $filters
     * @param  bool  $fullSnapshot  Carga inicial por filtros, sin ventana de cambios (requiere estado o región).
     *                              Uso puntual en horario nocturno (22:00–07:00, condiciones ChileCompra).
     */
    public function syncCompraAgil(
        string $trigger = 'manual',
        ?int $userId = null,
        ?int $windowMinutes = null,
        array $filters = [],
        ?int $maxPages = null,
        bool $fullSnapshot = false,
        int $startPage = 1,
    ): MercadoPublicoSyncRun {
        if ($fullSnapshot && empty($filters['statuses']) && empty($filters['regions'])) {
            throw new \InvalidArgumentException('La carga completa requiere filtrar por estado o región.');
        }
        if ($startPage > 1 && ! $fullSnapshot) {
            // En incremental, saltar páginas dejaría cambios sin procesar y luego avanzaría la marca.
            throw new \InvalidArgumentException('Solo una carga completa (--full) puede retomarse desde una página.');
        }

        $lock = Cache::lock('mercado-publico:sync:compra-agil', 3600);
        if (! $lock->get()) {
            return $this->skippedRun($trigger, $userId, 'Ya hay una sincronización de Compra Ágil en curso.');
        }

        try {
            return $this->runCompraAgil($trigger, $userId, $windowMinutes, $filters, $maxPages ?? $this->config->syncMaxPages(), $fullSnapshot, max(1, $startPage));
        } finally {
            $lock->release();
        }
    }

    private function runCompraAgil(string $trigger, ?int $userId, ?int $windowMinutes, array $filters, int $maxPages, bool $fullSnapshot, int $startPage): MercadoPublicoSyncRun
    {
        $startedAt = CarbonImmutable::now();
        $state = MercadoPublicoSyncState::query()->firstOrCreate(['key' => self::STATE_KEY_COMPRA_AGIL]);
        $windowMs = $fullSnapshot ? null : $this->windowMs($state, $startedAt, $windowMinutes);
        $regions = array_values(array_map('intval', $filters['regions'] ?? []));
        $statuses = array_values($filters['statuses'] ?? []);

        $run = MercadoPublicoSyncRun::create([
            'source_api' => 'compra_agil_v2',
            'endpoint' => 'GET /v2/compra-agil',
            'filters' => ['ttl_cambio_ms' => $windowMs, 'region' => $regions, 'estado' => $statuses, 'max_pages' => $maxPages, 'start_page' => $startPage],
            'trigger' => $trigger,
            'triggered_by_user_id' => $userId,
            'status' => MercadoPublicoSyncRun::STATUS_RUNNING,
            'started_at' => $startedAt,
        ]);
        $state->forceFill(['last_attempt_at' => $startedAt])->save();

        $requestsBefore = $this->adapter->requestCount();
        $query = new CompraAgilSearchQuery(
            changedWithinMs: $windowMs,
            statuses: $statuses,
            regions: $regions,
            pageSize: $this->config->pageSizeMax(),
            page: 1,
            orderBy: CompraAgilSearchQuery::ORDER_LAST_CHANGE,
        );

        $status = MercadoPublicoSyncRun::STATUS_SUCCEEDED;
        $counters = ['items_received' => 0, 'items_created' => 0, 'items_updated' => 0, 'items_unchanged' => 0];

        try {
            $page = $startPage;
            $pagesThisRun = 0;
            do {
                $pagesThisRun++;
                $result = $this->adapter->search($query->withPage($page));
                $fetchedAt = CarbonImmutable::now();

                foreach ($result->items as $dto) {
                    $outcome = $this->repository->upsert($dto, $fetchedAt);
                    $counters['items_received']++;
                    $counters['items_'.$outcome]++;
                }

                $run->forceFill($counters + [
                    'pages_total' => $result->totalPages,
                    'pages_processed' => $page,
                    'requests_used' => $this->adapter->requestCount() - $requestsBefore,
                ])->save();
                $state->forceFill(['checkpoint' => ['run' => $run->public_id, 'page' => $page, 'total_pages' => $result->totalPages]])->save();

                if ($result->isLastPage() || $result->totalPages === 0) {
                    break;
                }
                if ($pagesThisRun >= $maxPages) {
                    $status = MercadoPublicoSyncRun::STATUS_PARTIAL;
                    $run->error_code = 'MAX_PAGES_REACHED';
                    $run->error_summary = "Se alcanzó el máximo configurado de {$maxPages} páginas (última procesada: {$page}).";
                    break;
                }
                $page++;
            } while (true);
        } catch (MercadoPublicoException $e) {
            $status = $e->errorCode === MercadoPublicoException::QUOTA_EXHAUSTED
                ? MercadoPublicoSyncRun::STATUS_QUOTA_EXHAUSTED
                : MercadoPublicoSyncRun::STATUS_FAILED;
            $run->error_code = $e->errorCode;
            $run->error_summary = mb_substr($e->getMessage(), 0, 500);
        } catch (Throwable $e) {
            $status = MercadoPublicoSyncRun::STATUS_FAILED;
            $run->error_code = 'INTERNAL_ERROR';
            $run->error_summary = 'Error interno durante la sincronización.';
            // Mensaje acotado para diagnóstico (solo servidor). El ticket nunca viaja en V2 por URL ni en SQL.
            Log::error('mercado_publico.sync.internal_error', [
                'run' => $run->public_id,
                'exception' => $e::class,
                'message' => mb_substr(str_replace($this->config->ticket(), '[REDACTED]', $e->getMessage()), 0, 600),
            ]);
        }

        $finishedAt = CarbonImmutable::now();
        $run->forceFill([
            'status' => $status,
            'finished_at' => $finishedAt,
            'requests_used' => $this->adapter->requestCount() - $requestsBefore,
        ])->save();

        $stateUpdate = ['last_status' => $status];
        // Solo un recorrido incremental completo, sin filtros, avanza la marca incremental.
        if ($status === MercadoPublicoSyncRun::STATUS_SUCCEEDED && $regions === [] && $statuses === [] && $windowMinutes === null && ! $fullSnapshot) {
            $stateUpdate += ['last_success_started_at' => $startedAt, 'last_success_finished_at' => $finishedAt, 'checkpoint' => null];
        }
        $state->forceFill($stateUpdate)->save();

        return $run->refresh();
    }

    /**
     * Sincronización eficiente por defecto (TD-029): solo Compras Ágiles PUBLICADAS NUEVAS.
     *
     * Recorre estado=publicada ordenado por FechaPublicacion (más reciente primero; verificado con la
     * API real) y se detiene apenas una página llega a lo ya conocido (cursor = publicación más reciente
     * vista en el último recorrido completo, menos el solapamiento). Costo ≈ publicaciones nuevas / 50 + 1.
     *
     * Si se corta por el máximo de páginas o por cuota, guarda dónde quedó y la siguiente ejecución
     * CONTINÚA desde esa página (una antes, por si la lista se corrió con publicaciones nuevas),
     * en vez de volver a pedir las primeras páginas.
     *
     * No sigue los cambios de estado de todo el país (≈200 solicitudes/día): un proceso cuyo cierre
     * ya pasó se muestra como cerrado por fecha, y la ficha de detalle actualiza su estado al abrirla.
     */
    public function syncNewPublications(string $trigger = 'manual', ?int $userId = null, ?int $maxPages = null): MercadoPublicoSyncRun
    {
        $lock = Cache::lock('mercado-publico:sync:compra-agil', 3600);
        if (! $lock->get()) {
            return $this->skippedRun($trigger, $userId, 'Ya hay una sincronización de Compra Ágil en curso.');
        }

        try {
            return $this->runNewPublications($trigger, $userId, max(1, $maxPages ?? $this->config->syncMaxPages()));
        } finally {
            $lock->release();
        }
    }

    private function runNewPublications(string $trigger, ?int $userId, int $maxPages): MercadoPublicoSyncRun
    {
        $startedAt = CarbonImmutable::now();
        $state = MercadoPublicoSyncState::query()->firstOrCreate(['key' => self::STATE_KEY_PUBLICATIONS]);
        $checkpoint = $state->checkpoint ?? [];
        $resume = $checkpoint['resume'] ?? null;

        // Hasta dónde hay que llegar: lo ya conocido (menos solapamiento) o, la primera vez, la ventana inicial.
        $target = $resume
            ? CarbonImmutable::parse($resume['target_cursor'])
            : (isset($checkpoint['cursor'])
                ? CarbonImmutable::parse($checkpoint['cursor'])->subMinutes($this->config->syncOverlapMinutes())
                : $startedAt->subHours($this->config->syncInitialWindowHours()));
        $runTop = isset($resume['run_top']) ? CarbonImmutable::parse($resume['run_top']) : null;
        $startPage = $resume ? max(1, (int) $resume['page'] - 1) : 1;

        $run = MercadoPublicoSyncRun::create([
            'source_api' => 'compra_agil_v2',
            'endpoint' => 'GET /v2/compra-agil',
            'filters' => ['mode' => 'publications', 'estado' => ['publicada'], 'ordenar_por' => CompraAgilSearchQuery::ORDER_PUBLISHED, 'hasta_publicacion' => $target->toIso8601String(), 'start_page' => $startPage, 'resumed' => (bool) $resume, 'max_pages' => $maxPages],
            'trigger' => $trigger,
            'triggered_by_user_id' => $userId,
            'status' => MercadoPublicoSyncRun::STATUS_RUNNING,
            'started_at' => $startedAt,
        ]);
        $state->forceFill(['last_attempt_at' => $startedAt])->save();

        $requestsBefore = $this->adapter->requestCount();
        $query = new CompraAgilSearchQuery(statuses: ['publicada'], pageSize: $this->config->pageSizeMax(), page: 1, orderBy: CompraAgilSearchQuery::ORDER_PUBLISHED);
        $status = MercadoPublicoSyncRun::STATUS_SUCCEEDED;
        $counters = ['items_received' => 0, 'items_created' => 0, 'items_updated' => 0, 'items_unchanged' => 0];
        $completed = false;
        $page = $startPage;

        try {
            $pagesThisRun = 0;
            while (true) {
                $pagesThisRun++;
                $result = $this->adapter->search($query->withPage($page));
                $fetchedAt = CarbonImmutable::now();
                $oldest = null;
                foreach ($result->items as $dto) {
                    $outcome = $this->repository->upsert($dto, $fetchedAt);
                    $counters['items_received']++;
                    $counters['items_'.$outcome]++;
                    if ($dto->publishedAt) {
                        $runTop = $runTop === null || $dto->publishedAt->greaterThan($runTop) ? $dto->publishedAt : $runTop;
                        $oldest = $oldest === null || $dto->publishedAt->lessThan($oldest) ? $dto->publishedAt : $oldest;
                    }
                }
                $run->forceFill($counters + ['pages_total' => $result->totalPages, 'pages_processed' => $pagesThisRun, 'requests_used' => $this->adapter->requestCount() - $requestsBefore])->save();

                // Fin: se llegó a lo ya conocido o no hay más páginas.
                if ($result->items === [] || $result->isLastPage() || ($oldest !== null && $oldest->lessThanOrEqualTo($target))) {
                    $completed = true;
                    break;
                }
                if ($pagesThisRun >= $maxPages) {
                    $status = MercadoPublicoSyncRun::STATUS_PARTIAL;
                    $run->error_code = 'MAX_PAGES_REACHED';
                    $run->error_summary = "Se revisaron {$pagesThisRun} páginas sin llegar aún a lo ya conocido; la próxima sincronización continúa desde la página ".($page + 1).'.';
                    break;
                }
                $page++;
            }
        } catch (MercadoPublicoException $e) {
            $status = $e->errorCode === MercadoPublicoException::QUOTA_EXHAUSTED ? MercadoPublicoSyncRun::STATUS_QUOTA_EXHAUSTED : MercadoPublicoSyncRun::STATUS_FAILED;
            $run->error_code = $e->errorCode;
            $run->error_summary = mb_substr($e->getMessage(), 0, 500);
        } catch (Throwable $e) {
            $status = MercadoPublicoSyncRun::STATUS_FAILED;
            $run->error_code = 'INTERNAL_ERROR';
            $run->error_summary = 'Error interno durante la sincronización.';
            Log::error('mercado_publico.sync.internal_error', ['run' => $run->public_id, 'exception' => $e::class, 'message' => mb_substr(str_replace((string) $this->config->ticket(), '[REDACTED]', $e->getMessage()), 0, 600)]);
        }

        $finishedAt = CarbonImmutable::now();
        $run->forceFill(['status' => $status, 'finished_at' => $finishedAt, 'requests_used' => $this->adapter->requestCount() - $requestsBefore])->save();

        if ($completed) {
            // Nuevo cursor: la publicación más reciente vista en este recorrido completo.
            $cursor = $runTop ?? ($checkpoint['cursor'] ?? null);
            $state->forceFill([
                'last_status' => $status,
                'last_success_started_at' => $startedAt,
                'last_success_finished_at' => $finishedAt,
                'checkpoint' => ['cursor' => $cursor instanceof CarbonImmutable ? $cursor->toIso8601String() : $cursor, 'resume' => null],
            ])->save();
        } else {
            // Se guarda dónde continuar; el cursor anterior se conserva hasta completar el recorrido.
            $nextPage = $status === MercadoPublicoSyncRun::STATUS_PARTIAL ? $page + 1 : $page;
            $state->forceFill([
                'last_status' => $status,
                'checkpoint' => [
                    'cursor' => $checkpoint['cursor'] ?? null,
                    'resume' => ['page' => $nextPage, 'target_cursor' => $target->toIso8601String(), 'run_top' => $runTop?->toIso8601String()],
                ],
            ])->save();
        }

        return $run->refresh();
    }

    private function windowMs(MercadoPublicoSyncState $state, CarbonImmutable $now, ?int $windowMinutes): int
    {
        if ($windowMinutes !== null) {
            return max(1, $windowMinutes) * 60_000;
        }

        $last = $state->last_success_started_at;
        $minutes = $last
            ? (int) ceil($last->diffInSeconds($now, true) / 60) + $this->config->syncOverlapMinutes()
            : $this->config->syncInitialWindowHours() * 60;

        return min($minutes, $this->config->syncMaxWindowHours() * 60) * 60_000;
    }

    private function skippedRun(string $trigger, ?int $userId, string $reason): MercadoPublicoSyncRun
    {
        $now = CarbonImmutable::now();

        return MercadoPublicoSyncRun::create([
            'source_api' => 'compra_agil_v2',
            'endpoint' => 'GET /v2/compra-agil',
            'trigger' => $trigger,
            'triggered_by_user_id' => $userId,
            'status' => MercadoPublicoSyncRun::STATUS_SKIPPED,
            'started_at' => $now,
            'finished_at' => $now,
            'error_code' => 'ALREADY_RUNNING',
            'error_summary' => $reason,
        ]);
    }
}
