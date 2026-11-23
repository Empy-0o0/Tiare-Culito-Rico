<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Bodega de la empresa. */
class Warehouse extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $guarded = ['id', 'public_id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'region_code' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }
}
