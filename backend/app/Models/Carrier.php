<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/** Transportista u operador logístico aliado. */
class Carrier extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $guarded = ['id', 'public_id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'service_types' => 'array',
            'coverage_regions' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
