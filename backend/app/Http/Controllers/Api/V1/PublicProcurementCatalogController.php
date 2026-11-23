<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\PublicProcurement\ChileRegion;
use App\Domain\PublicProcurement\CompraAgilStatus;
use App\Domain\PublicProcurement\MercadoPublicoV1Catalog as V1;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/** Catálogos documentados por ChileCompra para filtros de la UI. */
class PublicProcurementCatalogController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return ApiResponse::ok([
            'regions' => collect(ChileRegion::NAMES)->map(fn ($name, $code) => ['code' => $code, 'name' => $name])->values(),
            'compra_agil_statuses' => collect(CompraAgilStatus::cases())
                ->reject(fn ($s) => $s === CompraAgilStatus::OcEmitida)
                ->map(fn ($s) => ['code' => $s->value, 'label' => $s->label()])->values(),
            'tender_statuses' => V1::options(V1::TENDER_STATUS),
            'tender_status_filters' => V1::options(V1::TENDER_STATUS_FILTERS),
            'tender_types' => V1::options(V1::TENDER_TYPES),
            'order_statuses' => V1::options(V1::ORDER_STATUS),
            'order_status_filters' => V1::options(V1::ORDER_STATUS_FILTERS),
        ]);
    }
}
