<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Alerta interna de la empresa sobre oportunidades públicas (nuevas coincidencias o cierre próximo). */
class PublicAlert extends Model
{
    use BelongsToTenant, HasPublicId;

    public const TYPES = ['NEW_MATCHES' => 'Nuevas coincidencias', 'CLOSING_SOON' => 'Cierre próximo'];

    protected $guarded = ['id', 'public_id', 'tenant_id'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function search(): BelongsTo
    {
        return $this->belongsTo(SavedPublicSearch::class, 'saved_search_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(PublicProcurementOpportunity::class, 'opportunity_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(PublicAlertRead::class, 'alert_id');
    }
}
