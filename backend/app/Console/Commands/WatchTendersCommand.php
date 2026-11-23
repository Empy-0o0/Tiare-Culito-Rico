<?php

namespace App\Console\Commands;

use App\Services\MercadoPublico\Matching\TenderWatchService;
use Illuminate\Console\Command;

/** Actualiza las licitaciones abiertas (API V1) que necesitan las búsquedas guardadas. */
class WatchTendersCommand extends Command
{
    protected $signature = 'public-procurement:watch-tenders {--max-details= : Tope de detalles a consultar en esta ejecución}';

    protected $description = 'Consulta las licitaciones activas y el detalle de las candidatas para las búsquedas guardadas.';

    public function handle(TenderWatchService $watch): int
    {
        $max = $this->option('max-details');
        $result = $watch->run($max !== null ? max(0, (int) $max) : null);

        if (isset($result['skipped'])) {
            $this->line('Sin ejecutar: '.$result['skipped']);

            return self::SUCCESS;
        }
        $this->line("Licitaciones activas: {$result['listed']}. Candidatas sin detalle: {$result['candidates']}. Detalles consultados: {$result['enriched']}. Fallidos: {$result['failed']}.");

        return self::SUCCESS;
    }
}
