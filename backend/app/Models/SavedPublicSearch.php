<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Búsqueda guardada de oportunidades públicas de una empresa. */
class SavedPublicSearch extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $guarded = ['id', 'public_id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'alerts_enabled' => 'boolean',
            'is_active' => 'boolean',
            'last_run_at' => 'immutable_datetime',
            'last_match_count' => 'integer',
        ];
    }

    public function matches(): HasMany
    {
        return $this->hasMany(PublicOpportunityMatch::class, 'saved_search_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
