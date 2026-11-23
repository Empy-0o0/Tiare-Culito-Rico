<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MercadoPublicoSyncState extends Model
{
    protected $table = 'mercado_publico_sync_state';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_success_started_at' => 'immutable_datetime',
            'last_success_finished_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'checkpoint' => 'array',
        ];
    }
}
