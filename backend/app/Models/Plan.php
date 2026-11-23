<?php

namespace App\Models;

use App\Support\PlanModules;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = ['key', 'name', 'description', 'modules', 'max_users', 'is_active'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['modules' => 'array', 'max_users' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return array<string, mixed> */
    public function toSummary(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'modules' => array_map(fn (string $m) => ['key' => $m, 'name' => PlanModules::label($m)], array_values($this->modules ?? [])),
            'max_users' => $this->max_users,
        ];
    }
}
