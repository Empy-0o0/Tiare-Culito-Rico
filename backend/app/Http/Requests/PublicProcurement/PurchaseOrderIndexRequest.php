<?php

namespace App\Http\Requests\PublicProcurement;

use App\Domain\PublicProcurement\MercadoPublicoV1Catalog;

class PurchaseOrderIndexRequest extends V1IndexRequest
{
    public const SORTABLE = ['issued_at', 'total', 'last_fetched_at'];

    protected function sortable(): array
    {
        return self::SORTABLE;
    }

    protected function sourceStatusFilters(): array
    {
        return MercadoPublicoV1Catalog::ORDER_STATUS_FILTERS;
    }

    protected function statusCodes(): array
    {
        return MercadoPublicoV1Catalog::ORDER_STATUS;
    }
}
