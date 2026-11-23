<?php

namespace App\Console\Commands;

use App\Services\MercadoPublico\Sync\MercadoPublicoQuotaService;
use App\Services\MercadoPublico\Sync\MercadoPublicoSyncService;
use Illuminate\Console\Command;

class SyncCompraAgilCommand extends Command
{
    protected $signature = 'tiare:mp:sync-compra-agil
        {--window= : Ventana en minutos (ttl_cambio_ms). Si se indica, no avanza la marca incremental}
        {--region=* : Códigos de región 1..16}
        {--estado=* : Estados Compra Ágil (publicada, cerrada, ...)}
        {--max-pages= : Máximo de páginas de 50 registros}
        {--full : Carga completa por estado/región, sin ventana de cambios (usar entre 22:00 y 07:00)}
        {--from-page=1 : Retoma una carga --full desde esta página (p. ej., tras agotar la cuota)}
        {--new : Solo publicaciones nuevas, con reanudación (modo eficiente, TD-029)}';

    protected $description = 'Sincroniza Compras Ágiles desde la API oficial V2 de Mercado Público (consume cuota del ticket).';

    public function handle(MercadoPublicoSyncService $sync, MercadoPublicoQuotaService $quota): int
    {
        $run = $this->option('new')
            ? $sync->syncNewPublications(trigger: 'manual', maxPages: $this->option('max-pages') !== null ? (int) $this->option('max-pages') : null)
            : $sync->syncCompraAgil(
                trigger: 'manual',
                windowMinutes: $this->option('window') !== null ? (int) $this->option('window') : null,
                filters: [
                    'regions' => array_map('intval', (array) $this->option('region')),
                    'statuses' => (array) $this->option('estado'),
                ],
                maxPages: $this->option('max-pages') !== null ? (int) $this->option('max-pages') : null,
                fullSnapshot: (bool) $this->option('full'),
                startPage: (int) $this->option('from-page'),
            );

        $this->table(['Campo', 'Valor'], [
            ['Estado', $run->status],
            ['Páginas', "{$run->pages_processed} / ".($run->pages_total ?? '?')],
            ['Solicitudes usadas', $run->requests_used],
            ['Recibidos', $run->items_received],
            ['Creados / actualizados / sin cambios', "{$run->items_created} / {$run->items_updated} / {$run->items_unchanged}"],
            ['Error', $run->error_code ? "{$run->error_code}: {$run->error_summary}" : '—'],
        ]);

        $usage = $quota->usageToday('v2');
        $this->line("Consumo V2 hoy ({$usage['date']}): {$usage['used']} / {$usage['budget']}");

        return in_array($run->status, ['succeeded', 'partial'], true) ? self::SUCCESS : self::FAILURE;
    }
}
