<?php

namespace App\Http\Resources;

use App\Domain\PublicProcurement\ChileRegion;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Organization */
class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'legal_name' => $this->legal_name,
            'trade_name' => $this->trade_name,
            'rut' => $this->rut,
            'kind' => ['code' => $this->kind?->value, 'label' => $this->kind?->label()],
            'segment' => $this->segment,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'address' => $this->address,
            'commune' => $this->commune,
            'region' => $this->region_code ? ['code' => $this->region_code, 'name' => ChileRegion::name($this->region_code)] : null,
            'notes' => $this->notes,
            'contacts' => $this->whenLoaded('contacts', fn () => ContactResource::collection($this->contacts)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
