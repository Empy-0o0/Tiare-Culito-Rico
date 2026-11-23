<?php

namespace App\Services\MercadoPublico\DTO;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Consulta de listado V1 (licitaciones.json / ordenesdecompra.json).
 *
 * Combinaciones documentadas por ChileCompra: sin filtros (día actual), fecha, estado,
 * fecha+estado, fecha+CodigoProveedor, fecha+CodigoOrganismo. La consulta por código
 * se resuelve como detalle (no pasa por aquí). `activas` solo se documenta sin fecha.
 */
final class V1SearchQuery
{
    public function __construct(
        public readonly ?CarbonImmutable $date = null,
        public readonly ?string $status = null,
        public readonly ?string $buyerCode = null,
        public readonly ?string $supplierCode = null,
    ) {
        if ($buyerCode !== null && $supplierCode !== null) {
            throw new InvalidArgumentException('No se puede filtrar por organismo y proveedor a la vez.');
        }
        if ($status === 'activas' && ($date !== null || $buyerCode !== null || $supplierCode !== null)) {
            throw new InvalidArgumentException('El estado "activas" se consulta sin fecha ni organismo/proveedor.');
        }
        foreach ([$buyerCode, $supplierCode] as $code) {
            if ($code !== null && ! preg_match('/^\d{1,12}$/', $code)) {
                throw new InvalidArgumentException('Los códigos de organismo y proveedor son numéricos.');
            }
        }
    }

    /** Organismo/proveedor requieren fecha según los ejemplos oficiales: se usa la fecha indicada o hoy. */
    public function effectiveDate(string $timezone): ?CarbonImmutable
    {
        if ($this->date !== null) {
            return $this->date;
        }

        return ($this->buyerCode !== null || $this->supplierCode !== null) ? CarbonImmutable::now($timezone)->startOfDay() : null;
    }

    /** @return array<string, string> parámetros GET (sin ticket) */
    public function toQuery(string $timezone): array
    {
        $query = [];
        if ($date = $this->effectiveDate($timezone)) {
            $query['fecha'] = $date->format('dmY'); // ddmmaaaa
        }
        if ($this->status !== null) {
            $query['estado'] = $this->status;
        }
        if ($this->buyerCode !== null) {
            $query['CodigoOrganismo'] = $this->buyerCode;
        }
        if ($this->supplierCode !== null) {
            $query['CodigoProveedor'] = $this->supplierCode;
        }

        return $query;
    }

    public function cacheKey(string $resource, string $timezone): string
    {
        $q = $this->toQuery($timezone);
        ksort($q);

        return 'mp:v1:'.$resource.':'.sha1(http_build_query($q));
    }

    /** Descripción legible de la consulta para la UI. */
    public function describe(string $timezone): array
    {
        return array_filter([
            'date' => $this->effectiveDate($timezone)?->toDateString(),
            'status' => $this->status,
            'buyer_code' => $this->buyerCode,
            'supplier_code' => $this->supplierCode,
        ], fn ($v) => $v !== null);
    }
}
