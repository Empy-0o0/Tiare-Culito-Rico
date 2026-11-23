<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublicOpportunityItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'line_number' => 'integer', 'awarded_quantity' => 'decimal:4', 'awarded_unit_price' => 'decimal:2'];
    }
}
