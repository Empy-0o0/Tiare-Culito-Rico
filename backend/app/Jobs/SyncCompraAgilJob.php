<?php

namespace App\Jobs;

use App\Services\MercadoPublico\MercadoPublicoConfig;
use App\Services\MercadoPublico\Sync\MercadoPublicoQuotaService;
use App\Services\MercadoPublico\Sync\MercadoPublicoSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sincronización de Compra Ágil en cola. Una a la vez. Sin reintentos automáticos.
 *
 * Modo por defecto (TD-029): solo publicaciones nuevas, con reanudación. La ejecución programada
 * corre como proceso automático: no puede usar la reserva de cuota para las personas.
 */
class SyncCompraAgilJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $trigger = 'scheduler',
        public readonly ?int $userId = null,
    ) {}

    public function handle(MercadoPublicoSyncService $sync, MercadoPublicoQuotaService $quota, MercadoPublicoConfig $config): void
    {
        $run = fn () => $config->syncMode() === 'changes'
            ? $sync->syncCompraAgil(trigger: $this->trigger, userId: $this->userId)
            : $sync->syncNewPublications(trigger: $this->trigger, userId: $this->userId);

        $this->trigger === 'scheduler' ? $quota->asBackground($run) : $run();
    }
}
