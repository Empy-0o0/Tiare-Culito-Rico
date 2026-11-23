<?php

namespace App\Services\MercadoPublico\Sync;

use App\Models\MercadoPublicoApiUsage;
use App\Services\MercadoPublico\Exceptions\MercadoPublicoException;
use App\Services\MercadoPublico\MercadoPublicoConfig;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Control local de cuota diaria por API (día calendario America/Santiago, igual que ChileCompra).
 * Se reserva ANTES de cada solicitud HTTP: si el presupuesto local se agotó o hay un bloqueo
 * por 429 vigente, no se llama a la API externa.
 */
class MercadoPublicoQuotaService
{
    /** true mientras corre un proceso automático: no puede usar la reserva interactiva (TD-029). */
    private static bool $background = false;

    public function __construct(private readonly MercadoPublicoConfig $config) {}

    /**
     * Ejecuta $fn como proceso automático (sincronización programada): sus solicitudes se detienen
     * al llegar a (presupuesto diario − reserva interactiva), para que siempre queden consultas
     * disponibles para las fichas y acciones de las personas.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public function asBackground(callable $fn): mixed
    {
        $previous = self::$background;
        self::$background = true;
        try {
            return $fn();
        } finally {
            self::$background = $previous;
        }
    }

    public function backgroundLimit(string $api): int
    {
        $budget = $this->config->dailyBudget($api);

        return $budget < 0 ? -1 : max(0, $budget - $this->config->interactiveReserve($api));
    }

    public function reserve(string $api): void
    {
        DB::transaction(function () use ($api): void {
            $usage = $this->todayRow($api, lock: true);
            $now = CarbonImmutable::now();

            if ($usage->blocked_until && $usage->blocked_until->greaterThan($now)) {
                throw MercadoPublicoException::quotaExhausted(
                    $usage->blocked_until,
                    'Cuota de Mercado Público agotada. Se reanudará después de '.$usage->blocked_until->setTimezone($this->config->businessTimezone())->format('d-m-Y H:i').'.'
                );
            }

            $budget = $this->config->dailyBudget($api);
            $limit = self::$background ? $this->backgroundLimit($api) : $budget;
            if (self::$background && $limit >= 0 && $usage->request_count >= $limit) {
                // No bloquea el día: solo detiene el proceso automático; las personas siguen consultando.
                throw MercadoPublicoException::quotaExhausted(
                    $this->nextBusinessDay(),
                    'La sincronización automática usó su parte de la cuota del día ('.$limit.' de '.$budget.'). Las '.($budget - $limit).' restantes quedan para fichas y consultas de las personas.'
                );
            }
            if ($budget >= 0 && $usage->request_count >= $budget) {
                throw MercadoPublicoException::quotaExhausted(
                    $this->nextBusinessDay(),
                    'Se alcanzó el límite operativo diario configurado para Mercado Público ('.$budget.' solicitudes).'
                );
            }

            $usage->increment('request_count');
        });
    }

    public function recordError(string $api): void
    {
        $this->todayRow($api)->increment('error_count');
    }

    public function blockUntil(string $api, CarbonImmutable $until): void
    {
        $row = $this->todayRow($api);
        $row->blocked_until = $until->utc();
        $row->save();
    }

    public function nextBusinessDay(): CarbonImmutable
    {
        return CarbonImmutable::now($this->config->businessTimezone())->addDay()->startOfDay()->utc();
    }

    /** @return array{api:string, date:string, used:int, errors:int, budget:int, remaining:int|null, blocked_until:?string} */
    public function usageToday(string $api): array
    {
        $row = MercadoPublicoApiUsage::query()->where('api', $api)->where('usage_date', $this->today())->first();
        $used = (int) ($row->request_count ?? 0);
        $budget = $this->config->dailyBudget($api);

        return [
            'api' => $api,
            'date' => $this->today(),
            'used' => $used,
            'errors' => (int) ($row->error_count ?? 0),
            'budget' => $budget,
            'remaining' => $budget >= 0 ? max(0, $budget - $used) : null,
            'background_limit' => $this->backgroundLimit($api),
            'interactive_reserve' => $this->config->interactiveReserve($api),
            'blocked_until' => $row?->blocked_until?->toIso8601String(),
        ];
    }

    private function today(): string
    {
        return CarbonImmutable::now($this->config->businessTimezone())->toDateString();
    }

    private function todayRow(string $api, bool $lock = false): MercadoPublicoApiUsage
    {
        $query = fn () => MercadoPublicoApiUsage::query()->where('api', $api)->where('usage_date', $this->today());

        $row = $lock ? $query()->lockForUpdate()->first() : $query()->first();
        if ($row) {
            return $row;
        }

        try {
            return MercadoPublicoApiUsage::create(['api' => $api, 'usage_date' => $this->today(), 'request_count' => 0, 'error_count' => 0]);
        } catch (QueryException) {
            // Otro proceso creó la fila en paralelo.
            return $lock ? $query()->lockForUpdate()->firstOrFail() : $query()->firstOrFail();
        }
    }
}
