<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Registro append-only de acciones sensibles. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'user_id', 'action', 'auditable_type', 'auditable_id', 'metadata', 'ip_address', 'request_id'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
