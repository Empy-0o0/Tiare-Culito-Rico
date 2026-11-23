<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'kind' => $this->kind,
            'sku' => $this->sku,
            'name' => $this->name,
            'description' => $this->description,
            'brand' => $this->brand,
            'public_catalog_code' => $this->public_catalog_code,
            'is_active' => $this->is_active,
            'unit' => $this->unit ? ['code' => $this->unit->code, 'name' => $this->unit->name] : null,
            'category' => $this->category ? new CategoryResource($this->category) : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
