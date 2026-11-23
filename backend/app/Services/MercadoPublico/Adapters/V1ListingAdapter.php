<?php

namespace App\Services\MercadoPublico\Adapters;

use App\Services\MercadoPublico\DTO\V1Record;
use App\Services\MercadoPublico\DTO\V1SearchQuery;
use App\Services\MercadoPublico\Exceptions\MercadoPublicoException;
use App\Services\MercadoPublico\Http\MercadoPublicoV1Client;
use App\Services\MercadoPublico\MercadoPublicoConfig;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Base de los adaptadores V1 con forma {Cantidad, FechaCreacion, Version, Listado[]}.
 */
abstract class V1ListingAdapter
{
    public function __construct(
        protected readonly MercadoPublicoV1Client $client,
        protected readonly MercadoPublicoConfig $config,
    ) {}

    /** @param 'tenders'|'purchase_orders' */
    abstract protected function resource(): string;

    abstract protected function normalize(array $raw): V1Record;

    abstract protected function notFoundMessage(string $code): string;

    /** @return list<V1Record> */
    public function search(V1SearchQuery $query): array
    {
        return $this->records($this->client->get($this->config->v1Path($this->resource()), $query->toQuery($this->config->businessTimezone())));
    }

    public function find(string $code): V1Record
    {
        $records = $this->records($this->client->get($this->config->v1Path($this->resource()), ['codigo' => $code]));
        foreach ($records as $record) {
            if (strcasecmp($record->code, $code) === 0) {
                return $record;
            }
        }

        throw new MercadoPublicoException(MercadoPublicoException::NOT_FOUND, $this->notFoundMessage($code), 404);
    }

    /** @return list<V1Record> */
    private function records(array $json): array
    {
        if (! array_key_exists('Listado', $json) || ! (is_array($json['Listado']) || $json['Listado'] === null)) {
            throw new MercadoPublicoException(MercadoPublicoException::INVALID_RESPONSE, 'Mercado Público (API V1) respondió sin el listado esperado.');
        }

        $records = [];
        $skipped = 0;
        foreach ((array) $json['Listado'] as $raw) {
            if (! is_array($raw)) {
                $skipped++;

                continue;
            }
            try {
                $records[] = $this->normalize($raw);
            } catch (InvalidArgumentException) {
                $skipped++;
            }
        }
        if ($skipped > 0) {
            Log::notice('mercado_publico.v1.records_skipped', ['resource' => $this->resource(), 'skipped' => $skipped]);
        }

        return $records;
    }
}
