<?php

namespace App\Services\MercadoPublico\V1;

use App\Models\PublicSupplier;
use App\Services\MercadoPublico\Adapters\EmpresasV1Adapter;
use App\Services\MercadoPublico\Exceptions\MercadoPublicoException;
use App\Support\ChileanRut;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Proveedor del Estado por RUT (BuscarProveedor). Resultado guardado 24 h para no repetir consultas.
 * El código obtenido permite consultar licitaciones y OC por CodigoProveedor.
 */
class SupplierLookupService
{
    private const CACHE_HOURS = 24;

    public function __construct(private readonly EmpresasV1Adapter $empresas) {}

    /** @return array{supplier: PublicSupplier, cached: bool} */
    public function lookup(string $rut, bool $refresh = false): array
    {
        $formatted = ChileanRut::format($rut);
        if ($formatted === null) {
            throw new InvalidArgumentException('RUT no válido.');
        }

        $local = PublicSupplier::query()->where('source_system', 'mercado_publico')->where('rut', $formatted)->first();
        if ($local && ! $refresh && $local->last_fetched_at?->greaterThan(CarbonImmutable::now()->subHours(self::CACHE_HOURS))) {
            return ['supplier' => $local, 'cached' => true];
        }

        // La API exige puntos, guion y DV; el ejemplo oficial usa "k" minúscula.
        $found = $this->empresas->supplierByRut(str_replace('K', 'k', $formatted));
        if ($found === null) {
            throw new MercadoPublicoException(MercadoPublicoException::NOT_FOUND, "No hay un proveedor registrado en Mercado Público con RUT {$formatted}.", 404);
        }

        $supplier = PublicSupplier::query()->updateOrCreate(
            ['source_system' => 'mercado_publico', 'external_code' => $found['code']],
            ['rut' => $formatted, 'name' => $found['name'], 'last_fetched_at' => CarbonImmutable::now()],
        );

        return ['supplier' => $supplier, 'cached' => false];
    }
}
