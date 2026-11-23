<?php

namespace App\Services\MercadoPublico;

/**
 * Lectura tipada de config/mercado_publico.php.
 * El ticket solo se entrega al cliente HTTP; nunca se serializa ni se registra.
 */
final class MercadoPublicoConfig
{
    public function enabled(): bool
    {
        return (bool) config('mercado_publico.enabled');
    }

    public function hasTicket(): bool
    {
        return trim((string) config('mercado_publico.ticket')) !== '';
    }

    /** @internal Solo para clientes HTTP. */
    public function ticket(): string
    {
        return trim((string) config('mercado_publico.ticket'));
    }

    public function v2BaseUrl(): string
    {
        return rtrim((string) config('mercado_publico.v2.base_url'), '/');
    }

    public function v1BaseUrl(): string
    {
        return rtrim((string) config('mercado_publico.v1.base_url'), '/');
    }

    /** @param 'tenders'|'purchase_orders'|'supplier_lookup'|'buyers' $resource */
    public function v1Path(string $resource): string
    {
        return '/'.ltrim((string) config("mercado_publico.v1.paths.{$resource}"), '/');
    }

    public function v1ListCacheMinutes(): int
    {
        return max(0, (int) config('mercado_publico.v1.list_cache_minutes', 30));
    }

    public function v1BuyersRefreshHours(): int
    {
        return max(1, (int) config('mercado_publico.v1.buyers_refresh_hours', 24));
    }

    public function v1Timeout(): int
    {
        return max(5, (int) config('mercado_publico.v1.http_timeout', 45));
    }

    public function dailyBudget(string $api): int
    {
        return (int) config("mercado_publico.{$api}.daily_budget", 0);
    }

    public function zIsLocal(): bool
    {
        return (bool) config('mercado_publico.v2.z_is_local', true);
    }

    public function pageSizeMin(): int
    {
        return (int) config('mercado_publico.v2.page_size_min', 10);
    }

    public function pageSizeMax(): int
    {
        return (int) config('mercado_publico.v2.page_size_max', 50);
    }

    public function timeout(): int
    {
        return (int) config('mercado_publico.http.timeout', 20);
    }

    public function connectTimeout(): int
    {
        return (int) config('mercado_publico.http.connect_timeout', 5);
    }

    public function maxRetries(): int
    {
        return max(0, (int) config('mercado_publico.http.max_retries', 2));
    }

    public function retryBaseMs(): int
    {
        return max(0, (int) config('mercado_publico.http.retry_base_ms', 500));
    }

    public function syncEnabled(): bool
    {
        return (bool) config('mercado_publico.sync.enabled');
    }

    public function syncIntervalMinutes(): int
    {
        return max(5, (int) config('mercado_publico.sync.interval_minutes', 30));
    }

    public function syncOverlapMinutes(): int
    {
        return max(0, (int) config('mercado_publico.sync.overlap_minutes', 15));
    }

    public function syncInitialWindowHours(): int
    {
        return max(1, (int) config('mercado_publico.sync.initial_window_hours', 24));
    }

    public function syncMaxWindowHours(): int
    {
        return max(1, (int) config('mercado_publico.sync.max_window_hours', 72));
    }

    public function syncMaxPages(): int
    {
        return max(1, (int) config('mercado_publico.sync.max_pages', 40));
    }

    public function cacheMinutes(): int
    {
        return max(0, (int) config('mercado_publico.cache_minutes', 60));
    }

    public function closedCacheHours(): int
    {
        return max(0, (int) config('mercado_publico.closed_cache_hours', 24));
    }

    public function interactiveReserve(string $api): int
    {
        return $api === 'v2' ? max(0, (int) config('mercado_publico.v2.interactive_reserve', 70)) : 0;
    }

    public function syncMode(): string
    {
        return config('mercado_publico.sync.mode') === 'changes' ? 'changes' : 'publications';
    }

    public function manualCooldownMinutes(): int
    {
        return max(0, (int) config('mercado_publico.sync.manual_cooldown_minutes', 10));
    }

    public function businessTimezone(): string
    {
        return (string) config('mercado_publico.business_timezone', 'America/Santiago');
    }
}
