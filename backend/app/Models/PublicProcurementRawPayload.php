<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Payload original de la API externa. No se expone por la API interna. */
class PublicProcurementRawPayload extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'fetched_at' => 'immutable_datetime'];
    }
}
