<?php

namespace App\Services\MercadoPublico\Adapters;

use App\Services\MercadoPublico\DTO\CompraAgilDTO;
use App\Services\MercadoPublico\DTO\CompraAgilPage;
use App\Services\MercadoPublico\DTO\CompraAgilSearchQuery;
use App\Services\MercadoPublico\Exceptions\MercadoPublicoException;
use App\Services\MercadoPublico\Http\MercadoPublicoV2Client;
use App\Services\MercadoPublico\MercadoPublicoConfig;
use App\Services\MercadoPublico\Normalizers\CompraAgilNormalizer;
use InvalidArgumentException;

/** Adaptador de los endpoints oficiales de Compra Ágil V2. */
class CompraAgilV2Adapter
{
    public function __construct(
        private readonly MercadoPublicoV2Client $client,
        private readonly CompraAgilNormalizer $normalizer,
        private readonly MercadoPublicoConfig $config,
    ) {}

    public function search(CompraAgilSearchQuery $query): CompraAgilPage
    {
        try {
            $params = $query->toQueryParams($this->config->pageSizeMin(), $this->config->pageSizeMax());
        } catch (InvalidArgumentException $e) {
            throw new MercadoPublicoException(MercadoPublicoException::INVALID_PARAMETERS, $e->getMessage());
        }

        $payload = $this->client->get('/v2/compra-agil', $params);

        $items = $payload['items'] ?? null;
        $pagination = $payload['paginacion'] ?? null;
        if (! is_array($items) || ! is_array($pagination)) {
            throw new MercadoPublicoException(MercadoPublicoException::INVALID_RESPONSE, 'Listado de Compra Ágil sin items o paginación.');
        }

        $dtos = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $dtos[] = $this->normalizer->normalize($item, isDetail: false);
            }
        }

        return new CompraAgilPage(
            items: $dtos,
            page: (int) ($pagination['numero_pagina'] ?? $query->page),
            pageSize: (int) ($pagination['tamano_pagina'] ?? $query->pageSize),
            totalPages: max(0, (int) ($pagination['total_paginas'] ?? 0)),
            totalResults: max(0, (int) ($pagination['total_resultados'] ?? 0)),
        );
    }

    public function find(string $code): CompraAgilDTO
    {
        if (! CompraAgilSearchQuery::isValidCode($code)) {
            throw new MercadoPublicoException(MercadoPublicoException::INVALID_PARAMETERS, 'Código de Compra Ágil con formato no válido.');
        }

        $payload = $this->client->get('/v2/compra-agil/'.rawurlencode($code));

        return $this->normalizer->normalize($payload, isDetail: true);
    }

    public function requestCount(): int
    {
        return $this->client->requestCount();
    }
}
