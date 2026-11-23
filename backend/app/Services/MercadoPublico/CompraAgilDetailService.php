<?php

namespace App\Services\MercadoPublico;

use App\Models\PublicProcurementOpportunity;
use App\Services\MercadoPublico\Adapters\CompraAgilV2Adapter;
use App\Services\MercadoPublico\Exceptions\MercadoPublicoException;
use App\Services\MercadoPublico\Persistence\CompraAgilRepository;
use Carbon\CarbonImmutable;

/**
 * Entrega el detalle de una Compra Ágil desde la base local, refrescándolo desde la API V2
 * si no existe o es más antiguo que MERCADO_PUBLICO_CACHE_MINUTES.
 * Si la API falla y hay copia local, se devuelve la copia marcada como desactualizada.
 */
class CompraAgilDetailService
{
    public function __construct(
        private readonly CompraAgilV2Adapter $adapter,
        private readonly CompraAgilRepository $repository,
        private readonly MercadoPublicoConfig $config,
    ) {}

    /** @return array{opportunity: PublicProcurementOpportunity, stale: bool, warning: ?string} */
    public function get(string $code, bool $forceRefresh = false): array
    {
        $local = $this->findLocal($code);
        $fresh = $local?->detail_fetched_at
            && $local->detail_fetched_at->greaterThan(CarbonImmutable::now()->subMinutes($this->freshForMinutes($local)));

        if ($local && $fresh && ! $forceRefresh) {
            return ['opportunity' => $local, 'stale' => false, 'warning' => null];
        }

        try {
            $this->repository->upsert($this->adapter->find($code));
        } catch (MercadoPublicoException $e) {
            if ($local && $e->errorCode !== MercadoPublicoException::NOT_FOUND) {
                return ['opportunity' => $local, 'stale' => true, 'warning' => $e->getMessage()];
            }
            throw $e;
        }

        return ['opportunity' => $this->findLocal($code), 'stale' => false, 'warning' => null];
    }

    /**
     * Vigencia de la ficha guardada (TD-029): un proceso abierto puede cambiar (cotizaciones, cierre),
     * uno finalizado casi no cambia. Así se evita gastar cuota cada vez que alguien reabre una ficha.
     */
    private function freshForMinutes(PublicProcurementOpportunity $o): int
    {
        $final = in_array($o->status_code, self::FINAL_STATUSES, true)
            || ($o->closes_at !== null && $o->closes_at->lessThan(CarbonImmutable::now()->subDay()));

        return $final ? $this->config->closedCacheHours() * 60 : $this->config->cacheMinutes();
    }

    private const FINAL_STATUSES = ['cerrada', 'desierta', 'cancelada', 'proveedor_seleccionado', 'oc_emitida'];

    private function findLocal(string $code): ?PublicProcurementOpportunity
    {
        return PublicProcurementOpportunity::query()
            ->where('source_system', PublicProcurementOpportunity::SOURCE_SYSTEM)
            ->where('source_type', PublicProcurementOpportunity::TYPE_COMPRA_AGIL)
            ->where('external_code', $code)
            ->with(['items', 'documents', 'detailPayload'])
            ->first();
    }
}
