<?php

namespace App\Services\MercadoPublico\Adapters;

use App\Services\MercadoPublico\DTO\V1Record;
use App\Services\MercadoPublico\Http\MercadoPublicoV1Client;
use App\Services\MercadoPublico\MercadoPublicoConfig;
use App\Services\MercadoPublico\Normalizers\LicitacionNormalizer;

/** GET /licitaciones.json (API V1). */
class LicitacionesV1Adapter extends V1ListingAdapter
{
    public function __construct(MercadoPublicoV1Client $client, MercadoPublicoConfig $config, private readonly LicitacionNormalizer $normalizer)
    {
        parent::__construct($client, $config);
    }

    protected function resource(): string
    {
        return 'tenders';
    }

    protected function normalize(array $raw): V1Record
    {
        return $this->normalizer->normalize($raw);
    }

    protected function notFoundMessage(string $code): string
    {
        return "La licitación {$code} no existe o no es pública en Mercado Público.";
    }
}
