<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Coincidencia entre una oportunidad pública y una búsqueda guardada de la empresa, con su motivo. */
class PublicOpportunityMatch extends Model
{
    use BelongsToTenant, HasPublicId;

    public const STATUSES = ['NEW' => 'Nueva', 'SEEN' => 'Vista', 'DISMISSED' => 'Descartada'];

    protected $guarded = ['id', 'public_id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'reasons' => 'array',
            'first_matched_at' => 'immutable_datetime',
            'last_matched_at' => 'immutable_datetime',
        ];
    }

    public function search(): BelongsTo
    {
        return $this->belongsTo(SavedPublicSearch::class, 'saved_search_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(PublicProcurementOpportunity::class, 'opportunity_id');
    }
}
