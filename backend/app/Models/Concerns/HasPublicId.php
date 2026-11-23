<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Identificador público opaco (ULID, 26 caracteres) generado por la aplicación.
 * La API expone public_id; nunca el id autoincremental.
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function ($model): void {
            if (empty($model->public_id)) {
                $model->public_id = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
