<?php

namespace App\Http\Resources;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Contact */
class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'full_name' => $this->full_name,
            'position' => $this->position,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_primary' => $this->is_primary,
        ];
    }
}
