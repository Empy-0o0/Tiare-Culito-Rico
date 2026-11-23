<?php

namespace App\Http\Resources;

use App\Domain\PublicProcurement\ChileRegion;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Supplier */
class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'data_origin' => 'client',
            'status' => ['code' => $this->status?->value, 'label' => $this->status?->label()],
            'coverage_regions' => collect($this->coverage_regions ?? [])->map(fn ($c) => ['code' => (int) $c, 'name' => ChileRegion::name((int) $c)])->values(),
            'payment_terms' => $this->payment_terms,
            'lead_time_days' => $this->lead_time_days,
            'notes' => $this->notes,
            'categories' => $this->whenLoaded('categories', fn () => CategoryResource::collection($this->categories)),
            'organization' => new OrganizationResource($this->organization),
            // Solo en el listado (withAvg/withCount): promedio de evaluaciones y documentos vencidos.
            'evaluation' => $this->when(isset($this->evaluations_count), fn () => [
                'count' => (int) $this->evaluations_count,
                'average' => $this->evaluations_avg_overall_score !== null ? number_format((float) $this->evaluations_avg_overall_score, 2, '.', '') : null,
            ]),
            'offerings_count' => $this->when(isset($this->offerings_count), fn () => (int) $this->offerings_count),
            'expired_documents' => $this->when(isset($this->expired_documents_count), fn () => (int) $this->expired_documents_count),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
