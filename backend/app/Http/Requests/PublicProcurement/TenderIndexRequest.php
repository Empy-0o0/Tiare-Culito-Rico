<?php

namespace App\Http\Requests\PublicProcurement;

use App\Domain\PublicProcurement\MercadoPublicoV1Catalog;

class TenderIndexRequest extends V1IndexRequest
{
    public const SORTABLE = ['closes_at', 'published_at', 'last_fetched_at', 'estimated_budget'];

    protected function sortable(): array
    {
        return self::SORTABLE;
    }

    protected function sourceStatusFilters(): array
    {
        return MercadoPublicoV1Catalog::TENDER_STATUS_FILTERS;
    }

    protected function statusCodes(): array
    {
        return MercadoPublicoV1Catalog::TENDER_STATUS;
    }

    public function rules(): array
    {
        return parent::rules() + ['type' => ['nullable', 'string', 'regex:/^[A-Z0-9]{2,4}$/'], 'region' => ['nullable', 'integer', 'between:1,16']];
    }
}
