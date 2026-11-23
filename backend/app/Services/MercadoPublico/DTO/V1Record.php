<?php

namespace App\Services\MercadoPublico\DTO;

/**
 * Registro normalizado de la API V1 (licitación u orden de compra).
 * `attributes` son columnas del modelo destino ya validadas (fechas UTC, montos como string decimal).
 * `raw` es el elemento original sin datos personales de contacto.
 */
final class V1Record
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $code,
        public readonly bool $isDetail,
        public readonly array $attributes,
        public readonly array $items,
        public readonly array $raw,
        public readonly string $payloadHash,
    ) {}
}
