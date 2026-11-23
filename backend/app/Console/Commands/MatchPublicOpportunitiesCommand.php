<?php

namespace App\Console\Commands;

use App\Models\SavedPublicSearch;
use App\Models\Tenant;
use App\Services\MercadoPublico\Matching\OpportunityMatcher;
use App\Support\CurrentTenant;
use Illuminate\Console\Command;

/**
 * Ejecuta las búsquedas guardadas activas de cada empresa contra los datos ya
 * sincronizados y genera alertas internas. No consulta Mercado Público.
 */
class MatchPublicOpportunitiesCommand extends Command
{
    protected $signature = 'public-procurement:match {--tenant= : ID de una empresa específica}';

    protected $description = 'Busca coincidencias de las búsquedas guardadas y genera alertas (nuevas y cierre próximo).';

    public function handle(OpportunityMatcher $matcher, CurrentTenant $current): int
    {
        $tenantIds = SavedPublicSearch::withoutTenantScope()->where('is_active', true)
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('tenant_id', (int) $t))
            ->distinct()->pluck('tenant_id');

        foreach (Tenant::query()->whereIn('id', $tenantIds)->where('status', 'ACTIVE')->get() as $tenant) {
            $current->set($tenant);
            $new = 0;
            foreach (SavedPublicSearch::query()->where('is_active', true)->get() as $search) {
                $new += $matcher->run($search, notify: true)['new'];
            }
            $closing = $matcher->closingSoon();
            $this->line("Empresa {$tenant->id}: {$new} coincidencias nuevas, {$closing} alertas de cierre.");
        }
        $current->set(null);

        return self::SUCCESS;
    }
}
