<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Organismo comprador según BuscarComprador (API V1). Dato oficial. */
class PublicBuyer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_fetched_at' => 'immutable_datetime'];
    }
}
