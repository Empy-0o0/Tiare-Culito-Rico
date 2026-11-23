<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Support\CurrentTenant;
use Illuminate\Http\Request;

class AuditLogger
{
    public function __construct(private readonly CurrentTenant $current, private readonly Request $request) {}

    /** @param array<string, mixed> $metadata Nunca incluir secretos ni contraseñas. */
    public function log(string $action, ?int $userId = null, ?string $auditableType = null, ?string $auditableId = null, array $metadata = []): void
    {
        AuditLog::create([
            'tenant_id' => $this->current->id(),
            'user_id' => $userId ?? $this->request->user()?->getKey(),
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'metadata' => $metadata ?: null,
            'ip_address' => $this->request->ip(),
            'request_id' => $this->request->attributes->get('request_id'),
        ]);
    }
}
