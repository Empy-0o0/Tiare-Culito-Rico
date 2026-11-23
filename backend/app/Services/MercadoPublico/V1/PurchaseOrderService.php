<?php

namespace App\Services\MercadoPublico\V1;

use App\Models\PublicPurchaseOrder;
use App\Services\MercadoPublico\Adapters\OrdenesCompraV1Adapter;
use App\Services\MercadoPublico\Adapters\V1ListingAdapter;
use App\Services\MercadoPublico\MercadoPublicoConfig;
use App\Services\MercadoPublico\Persistence\PurchaseOrderRepository;
use Illuminate\Database\Eloquent\Model;

/** Órdenes de compra públicas (API V1). */
class PurchaseOrderService extends V1RecordService
{
    public function __construct(
        MercadoPublicoConfig $config,
        private readonly OrdenesCompraV1Adapter $ordenes,
        private readonly PurchaseOrderRepository $repository,
    ) {
        parent::__construct($config);
    }

    protected function adapter(): V1ListingAdapter
    {
        return $this->ordenes;
    }

    protected function persist(array $records): array
    {
        return $this->repository->upsertMany($records);
    }

    protected function resource(): string
    {
        return 'purchase_orders';
    }

    protected function findLocal(string $code): ?Model
    {
        return PublicPurchaseOrder::query()
            ->where('source_system', PublicPurchaseOrder::SOURCE_SYSTEM)
            ->where('external_code', $code)
            ->first();
    }
}
