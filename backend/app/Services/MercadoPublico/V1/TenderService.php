<?php

namespace App\Services\MercadoPublico\V1;

use App\Models\PublicProcurementOpportunity;
use App\Services\MercadoPublico\Adapters\LicitacionesV1Adapter;
use App\Services\MercadoPublico\Adapters\V1ListingAdapter;
use App\Services\MercadoPublico\MercadoPublicoConfig;
use App\Services\MercadoPublico\Persistence\LicitacionRepository;
use Illuminate\Database\Eloquent\Model;

/** Licitaciones (API V1). */
class TenderService extends V1RecordService
{
    public function __construct(
        MercadoPublicoConfig $config,
        private readonly LicitacionesV1Adapter $licitaciones,
        private readonly LicitacionRepository $repository,
    ) {
        parent::__construct($config);
    }

    protected function adapter(): V1ListingAdapter
    {
        return $this->licitaciones;
    }

    protected function persist(array $records): array
    {
        return $this->repository->upsertMany($records);
    }

    protected function resource(): string
    {
        return 'tenders';
    }

    protected function findLocal(string $code): ?Model
    {
        return PublicProcurementOpportunity::query()
            ->where('source_system', PublicProcurementOpportunity::SOURCE_SYSTEM)
            ->where('source_type', PublicProcurementOpportunity::TYPE_LICITACION)
            ->where('external_code', $code)
            ->with('items')
            ->first();
    }
}
