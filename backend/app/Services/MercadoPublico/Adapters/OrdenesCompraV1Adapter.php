<?php

namespace App\Services\MercadoPublico\Adapters;

use App\Services\MercadoPublico\DTO\V1Record;
use App\Services\MercadoPublico\Http\MercadoPublicoV1Client;
use App\Services\MercadoPublico\MercadoPublicoConfig;
use App\Services\MercadoPublico\Normalizers\OrdenCompraNormalizer;

/** GET /ordenesdecompra.json (API V1). */
class OrdenesCompraV1Adapter extends V1ListingAdapter
{
    public function __construct(MercadoPublicoV1Client $client, MercadoPublicoConfig $config, private readonly OrdenCompraNormalizer $normalizer)
    {
        parent::__construct($client, $config);
    }

    protected function resource(): string
    {
        return 'purchase_orders';
    }

    protected function normalize(array $raw): V1Record
    {
        return $this->normalizer->normalize($raw);
    }

    protected function notFoundMessage(string $code): string
    {
        return "La orden de compra {$code} no existe o no es pública en Mercado Público.";
    }
}
