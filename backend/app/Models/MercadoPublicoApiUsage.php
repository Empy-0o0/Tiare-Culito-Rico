<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MercadoPublicoApiUsage extends Model
{
    protected $table = 'mercado_publico_api_usage';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'blocked_until' => 'immutable_datetime',
            'request_count' => 'integer',
            'error_count' => 'integer',
        ];
    }
}
