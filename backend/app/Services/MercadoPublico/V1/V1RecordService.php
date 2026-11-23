<?php

namespace App\Services\MercadoPublico\V1;

use App\Services\MercadoPublico\Adapters\V1ListingAdapter;
use App\Services\MercadoPublico\DTO\V1Record;
use App\Services\MercadoPublico\DTO\V1SearchQuery;
use App\Services\MercadoPublico\Exceptions\MercadoPublicoException;
use App\Services\MercadoPublico\MercadoPublicoConfig;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Búsqueda y detalle de registros V1 con persistencia local.
 *
 * - search(): una solicitud V1 devuelve el listado completo de la consulta (sin paginación).
 *   Se guardan los registros y la lista de códigos en caché por MERCADO_PUBLICO_V1_LIST_CACHE_MINUTES,
 *   así paginar y filtrar no vuelve a consumir cuota.
 * - detail(): devuelve la copia local si es reciente; si no, consulta por código. Si la API falla
 *   y hay copia local, la entrega marcada como desactualizada.
 */
abstract class V1RecordService
{
    public function __construct(protected readonly MercadoPublicoConfig $config) {}

    abstract protected function adapter(): V1ListingAdapter;

    /** @param list<V1Record> $records */
    abstract protected function persist(array $records): array;

    abstract protected function findLocal(string $code): ?Model;

    abstract protected function resource(): string;

    /** @return array{codes: list<string>, fetched_at: string, from_cache: bool, summary: array<string,int>|null, query: array} */
    public function search(V1SearchQuery $query, bool $refresh = false): array
    {
        $tz = $this->config->businessTimezone();
        $key = $query->cacheKey($this->resource(), $tz);

        if (! $refresh && is_array($cached = Cache::get($key))) {
            return $cached + ['from_cache' => true];
        }

        $records = $this->adapter()->search($query);
        $summary = $this->persist($records);

        $result = [
            'codes' => array_values(array_unique(array_map(fn ($r) => $r->code, $records))),
            'fetched_at' => CarbonImmutable::now()->toIso8601String(),
            'summary' => $summary,
            'query' => $query->describe($tz),
        ];
        if ($this->config->v1ListCacheMinutes() > 0) {
            Cache::put($key, $result, now()->addMinutes($this->config->v1ListCacheMinutes()));
        }

        return $result + ['from_cache' => false];
    }

    /** @return array{record: Model, stale: bool, warning: ?string} */
    public function detail(string $code, bool $refresh = false): array
    {
        $local = $this->findLocal($code);
        $fresh = $local?->detail_fetched_at
            && $local->detail_fetched_at->greaterThan(CarbonImmutable::now()->subMinutes($this->config->cacheMinutes()));

        if ($local && $fresh && ! $refresh) {
            return ['record' => $local, 'stale' => false, 'warning' => null];
        }

        try {
            $this->persist([$this->adapter()->find($code)]);
        } catch (MercadoPublicoException $e) {
            if ($local && $e->errorCode !== MercadoPublicoException::NOT_FOUND) {
                return ['record' => $local, 'stale' => true, 'warning' => $e->getMessage()];
            }
            throw $e;
        }

        return ['record' => $this->findLocal($code), 'stale' => false, 'warning' => null];
    }
}
