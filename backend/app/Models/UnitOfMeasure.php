<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Catálogo global de referencia (no es dato privado de un tenant). */
class UnitOfMeasure extends Model
{
    protected $table = 'units_of_measure';

    protected $fillable = ['code', 'name'];
}
