<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Marca de lectura de una alerta por usuario. */
class PublicAlertRead extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime'];
    }
}
