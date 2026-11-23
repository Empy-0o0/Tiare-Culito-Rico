<?php

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Customer */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'data_origin' => 'client',
            'status' => ['code' => $this->status?->value, 'label' => $this->status?->label()],
            'plan' => $this->plan,
            'source' => $this->source,
            'owner' => $this->owner ? ['public_id' => $this->owner->public_id, 'name' => $this->owner->name] : null,
            'next_action_at' => $this->next_action_at?->format('Y-m-d'),
            'next_action_summary' => $this->next_action_summary,
            'organization' => new OrganizationResource($this->organization),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
