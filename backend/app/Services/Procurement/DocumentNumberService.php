<?php

namespace App\Services\Procurement;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Correlativos por empresa, tipo y año en hora de Chile: SC-2026-0001 (solicitud), OCI-2026-0001 (orden interna).
 * Se toma con bloqueo de fila dentro de la transacción del llamador: sin números repetidos ni saltos por concurrencia.
 */
class DocumentNumberService
{
    public const REQUEST = 'SC';

    public const ORDER = 'OCI';

    public const SHIPMENT = 'DSP';

    public function next(int $tenantId, string $kind): string
    {
        $year = (int) CarbonImmutable::now('America/Santiago')->format('Y');

        return DB::transaction(function () use ($tenantId, $kind, $year) {
            $row = DB::table('document_sequences')->where(compact('kind', 'year') + ['tenant_id' => $tenantId])->lockForUpdate()->first();
            if (! $row) {
                try {
                    DB::table('document_sequences')->insert(['tenant_id' => $tenantId, 'kind' => $kind, 'year' => $year, 'last_number' => 0]);
                } catch (QueryException) {
                    // Otro proceso la creó en paralelo.
                }
                $row = DB::table('document_sequences')->where(compact('kind', 'year') + ['tenant_id' => $tenantId])->lockForUpdate()->first();
            }
            $next = $row->last_number + 1;
            DB::table('document_sequences')->where('id', $row->id)->update(['last_number' => $next]);

            return sprintf('%s-%d-%04d', $kind, $year, $next);
        });
    }
}
