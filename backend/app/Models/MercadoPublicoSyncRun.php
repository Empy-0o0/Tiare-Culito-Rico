<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class MercadoPublicoSyncRun extends Model
{
    use HasPublicId;

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    public const STATUS_QUOTA_EXHAUSTED = 'quota_exhausted';

    public const STATUS_SKIPPED = 'skipped';

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
