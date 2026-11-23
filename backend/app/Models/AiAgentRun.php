<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Ejecución de un agente IA. El resultado es inmutable: después de creada solo
 * se registra la revisión humana (una vez).
 */
class AiAgentRun extends Model
{
    use BelongsToTenant, HasPublicId;

    public const REVIEW_FIELDS = ['review_status', 'reviewed_by', 'reviewed_at', 'review_comment', 'updated_at'];

    protected $guarded = ['id', 'public_id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'input_snapshot' => 'array',
            'facts' => 'array',
            'analysis' => 'array',
            'reviewed_at' => 'immutable_datetime',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'latency_ms' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $run): void {
            $changed = array_keys($run->getDirty());
            if (array_diff($changed, self::REVIEW_FIELDS) !== []) {
                throw new LogicException('El resultado de un agente no se modifica; solo se registra su revisión.');
            }
            if ($run->getOriginal('review_status') !== 'PENDING') {
                throw new LogicException('La ejecución ya fue revisada.');
            }
        });
        static::deleting(fn () => throw new LogicException('Las ejecuciones de agentes no se eliminan.'));
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by')->withTrashed();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }
}
