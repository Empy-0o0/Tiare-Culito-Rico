<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class TenantSubscription extends Model
{
    use HasPublicId;

    public const STATUSES = ['active', 'suspended', 'cancelled'];

    public const STATUS_LABELS = ['active' => 'Vigente', 'suspended' => 'Suspendido', 'cancelled' => 'Terminado'];

    protected $fillable = ['tenant_id', 'plan_id', 'status', 'starts_on', 'ends_on', 'notes', 'created_by_user_id'];

    protected $hidden = ['id', 'tenant_id', 'plan_id', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Suscripciones vigentes hoy (hora de Chile). */
    public function scopeCurrent(Builder $query): Builder
    {
        $today = Carbon::now('America/Santiago')->toDateString();

        return $query->where('status', 'active')
            ->whereDate('starts_on', '<=', $today)
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today));
    }

    /** @return array<string, mixed> */
    public function toSummary(): array
    {
        return [
            'public_id' => $this->public_id,
            'status' => $this->status,
            'status_label' => self::STATUS_LABELS[$this->status] ?? $this->status,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'plan' => $this->plan?->toSummary(),
        ];
    }
}
