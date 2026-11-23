<?php

namespace App\Services\MercadoPublico\DTO;

use App\Domain\PublicProcurement\ChileRegion;
use App\Domain\PublicProcurement\CompraAgilStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Parámetros del listado GET /v2/compra-agil, validados según la guía oficial v3.0
 * y el comportamiento real observado (tamano_pagina 10..50).
 */
final class CompraAgilSearchQuery
{
    public const ORDER_LAST_CHANGE = 'FechaUltimaModificacion';

    public const ORDER_PUBLISHED = 'FechaPublicacion';

    /**
     * @param  list<string>  $statuses
     * @param  list<int>  $regions
     */
    public function __construct(
        public readonly ?int $changedWithinMs = null,
        public readonly ?CarbonImmutable $changedFrom = null,
        public readonly ?CarbonImmutable $changedTo = null,
        public readonly ?CarbonImmutable $publishedFrom = null,
        public readonly ?CarbonImmutable $publishedTo = null,
        public readonly array $statuses = [],
        public readonly array $regions = [],
        public readonly ?string $code = null,
        public readonly ?string $keywords = null,
        public readonly int $pageSize = 50,
        public readonly int $page = 1,
        public readonly string $orderBy = self::ORDER_LAST_CHANGE,
    ) {}

    public function withPage(int $page): self
    {
        $clone = get_object_vars($this);
        $clone['page'] = $page;

        return new self(...$clone);
    }

    /**
     * @return array<string, scalar>
     *
     * @throws InvalidArgumentException
     */
    public function toQueryParams(int $pageSizeMin = 10, int $pageSizeMax = 50): array
    {
        if ($this->changedWithinMs !== null && ($this->changedFrom || $this->changedTo)) {
            throw new InvalidArgumentException('ttl_cambio_ms no puede combinarse con cambio_desde/cambio_hasta.');
        }
        if (($this->changedFrom === null) !== ($this->changedTo === null)) {
            throw new InvalidArgumentException('cambio_desde y cambio_hasta deben enviarse juntos.');
        }
        if ($this->changedWithinMs !== null && $this->changedWithinMs <= 0) {
            throw new InvalidArgumentException('ttl_cambio_ms debe ser positivo.');
        }
        if ($this->code !== null && $this->keywords !== null) {
            throw new InvalidArgumentException('id y q son mutuamente excluyentes.');
        }
        if ($this->pageSize < $pageSizeMin || $this->pageSize > $pageSizeMax) {
            throw new InvalidArgumentException("tamano_pagina debe estar entre {$pageSizeMin} y {$pageSizeMax}.");
        }
        if ($this->page < 1) {
            throw new InvalidArgumentException('numero_pagina comienza en 1.');
        }
        if (! in_array($this->orderBy, [self::ORDER_LAST_CHANGE, self::ORDER_PUBLISHED], true)) {
            throw new InvalidArgumentException('ordenar_por no permitido.');
        }
        foreach ($this->statuses as $status) {
            if (! in_array($status, CompraAgilStatus::values(), true)) {
                throw new InvalidArgumentException("Estado no permitido: {$status}.");
            }
        }
        foreach ($this->regions as $region) {
            if (! ChileRegion::isValid($region)) {
                throw new InvalidArgumentException("Región no válida: {$region}.");
            }
        }
        if ($this->code !== null && ! self::isValidCode($this->code)) {
            throw new InvalidArgumentException('Código de Compra Ágil con formato no válido.');
        }

        $params = array_filter([
            'ttl_cambio_ms' => $this->changedWithinMs,
            'cambio_desde' => $this->changedFrom?->utc()->format('Y-m-d\TH:i:s\Z'),
            'cambio_hasta' => $this->changedTo?->utc()->format('Y-m-d\TH:i:s\Z'),
            'publicado_desde' => $this->publishedFrom?->utc()->format('Y-m-d\TH:i:s\Z'),
            'publicado_hasta' => $this->publishedTo?->utc()->format('Y-m-d\TH:i:s\Z'),
            'estado' => $this->statuses ? implode(',', array_values(array_unique($this->statuses))) : null,
            'region' => $this->regions ? implode(',', array_values(array_unique($this->regions))) : null,
            'id' => $this->code,
            'q' => $this->keywords !== null ? mb_substr(trim($this->keywords), 0, 120) : null,
        ], fn ($v) => $v !== null && $v !== '');

        $params['tamano_pagina'] = $this->pageSize;
        $params['numero_pagina'] = $this->page;
        $params['ordenar_por'] = $this->orderBy;

        return $params;
    }

    /** Ej.: 1057539-228-COT26. Solo letras, dígitos y guiones. */
    public static function isValidCode(string $code): bool
    {
        return (bool) preg_match('/^[0-9A-Za-z]{1,12}(-[0-9A-Za-z]{1,12}){1,4}$/', $code);
    }
}
