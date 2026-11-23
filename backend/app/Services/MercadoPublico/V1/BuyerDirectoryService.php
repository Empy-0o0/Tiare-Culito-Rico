<?php

namespace App\Services\MercadoPublico\V1;

use App\Models\PublicBuyer;
use App\Services\MercadoPublico\Adapters\EmpresasV1Adapter;
use App\Services\MercadoPublico\MercadoPublicoConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Directorio de organismos compradores (BuscarComprador). La API devuelve la lista completa en una
 * solicitud: se guarda localmente y se refresca como máximo cada MERCADO_PUBLICO_V1_BUYERS_REFRESH_HOURS.
 */
class BuyerDirectoryService
{
    public function __construct(
        private readonly EmpresasV1Adapter $empresas,
        private readonly MercadoPublicoConfig $config,
    ) {}

    public function lastFetchedAt(): ?CarbonImmutable
    {
        $max = PublicBuyer::query()->where('source_system', 'mercado_publico')->max('last_fetched_at');

        return $max ? CarbonImmutable::parse($max, 'UTC') : null;
    }

    public function isStale(): bool
    {
        $last = $this->lastFetchedAt();

        return $last === null || $last->lessThan(CarbonImmutable::now()->subHours($this->config->v1BuyersRefreshHours()));
    }

    /** @return array{received:int, fetched_at:string} */
    public function refresh(): array
    {
        $rows = $this->empresas->buyers();
        $now = CarbonImmutable::now();

        DB::transaction(function () use ($rows, $now): void {
            foreach (array_chunk($rows, 500) as $chunk) {
                PublicBuyer::query()->upsert(
                    array_map(fn ($r) => [
                        'source_system' => 'mercado_publico',
                        'external_code' => $r['code'],
                        'name' => $r['name'],
                        'last_fetched_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $chunk),
                    ['source_system', 'external_code'],
                    ['name', 'last_fetched_at', 'updated_at'],
                );
            }
        });

        return ['received' => count($rows), 'fetched_at' => $now->toIso8601String()];
    }
}
