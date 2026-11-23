<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Proveedor según BuscarProveedor (API V1), consultado por RUT. Dato oficial. */
class PublicSupplier extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_fetched_at' => 'immutable_datetime'];
    }
}
