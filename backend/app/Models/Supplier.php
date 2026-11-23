<?php

namespace App\Models;

use App\Domain\Crm\SupplierStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = ['organization_id', 'status', 'coverage_regions', 'payment_terms', 'lead_time_days', 'notes'];

    protected function casts(): array
    {
        return ['status' => SupplierStatus::class, 'coverage_regions' => 'array', 'lead_time_days' => 'integer'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'supplier_categories');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(SupplierEvaluation::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(SupplierDocument::class);
    }

    /** Productos/servicios que entrega y su capacidad (TD-026). */
    public function offerings(): HasMany
    {
        return $this->hasMany(SupplierOffering::class);
    }
}
