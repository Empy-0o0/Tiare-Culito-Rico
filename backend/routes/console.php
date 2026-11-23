<?php

use App\Jobs\SyncCompraAgilJob;
use Illuminate\Support\Facades\Schedule;

/*
| Sincronización programada de Compra Ágil V2. Desactivada por defecto
| (MERCADO_PUBLICO_SYNC_ENABLED=false). Requiere `php artisan schedule:work` (o cron)
| y un worker de cola (`php artisan queue:work`).
*/
if (config('mercado_publico.enabled') && config('mercado_publico.sync.enabled')) {
    $interval = max(5, (int) config('mercado_publico.sync.interval_minutes', 30));

    Schedule::job(new SyncCompraAgilJob('scheduler'))
        ->cron("*/{$interval} * * * *")
        ->name('mercado-publico:sync-compra-agil')
        ->withoutOverlapping(60);
}

/*
| Matching de búsquedas guardadas y alertas internas. Usa solo datos ya sincronizados
| (no consume cuota de Mercado Público). Activo por defecto; se apaga con
| MERCADO_PUBLICO_MATCHING_ENABLED=false.
*/
if (config('mercado_publico.matching.enabled', true)) {
    Schedule::command('public-procurement:match')
        ->everyFifteenMinutes()
        ->name('mercado-publico:match-saved-searches')
        ->withoutOverlapping(30);
}

/*
| Licitaciones para búsquedas guardadas (API V1): consulta las activas y el detalle de las
| candidatas cada pocas horas. Solo corre si Mercado Público está habilitado, hay ticket y
| alguna búsqueda activa incluye licitaciones.
*/
if (config('mercado_publico.enabled') && config('mercado_publico.matching.tender_watch_enabled', true)) {
    $hours = max(1, (int) config('mercado_publico.matching.tender_watch_interval_hours', 3));

    Schedule::command('public-procurement:watch-tenders')
        ->cron("5 */{$hours} * * *")
        ->name('mercado-publico:watch-tenders')
        ->withoutOverlapping(120);
}
