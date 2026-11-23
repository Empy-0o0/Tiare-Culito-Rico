<?php

namespace App\Services\MercadoPublico\Matching;

use App\Models\Product;
use App\Models\PublicProcurementOpportunity;
use App\Models\SavedPublicSearch;
use App\Models\Tenant;
use App\Services\MercadoPublico\DTO\V1SearchQuery;
use App\Services\MercadoPublico\Exceptions\MercadoPublicoException;
use App\Services\MercadoPublico\MercadoPublicoConfig;
use App\Services\MercadoPublico\V1\TenderService;
use App\Support\CurrentTenant;
use App\Support\WordMatcher;
use Carbon\CarbonImmutable;

/**
 * Mantiene al día las licitaciones abiertas que necesitan las búsquedas guardadas:
 *  1. una consulta `licitaciones.json?estado=activas` (código, nombre, estado y cierre);
 *  2. el detalle (organismo, región, monto, productos) solo de las licitaciones candidatas
 *     para alguna búsqueda, con un tope de consultas por ejecución.
 * No hace nada si ninguna búsqueda activa incluye licitaciones (no gasta cuota sin necesidad).
 */
final class TenderWatchService
{
    public const OPEN_STATUS = '5'; // Publicada (diccionario V1)

    public function __construct(
        private readonly TenderService $tenders,
        private readonly MercadoPublicoConfig $config,
        private readonly CurrentTenant $current,
    ) {}

    /**
     * @return array{skipped?: string, listed?: int, candidates?: int, enriched?: int, failed?: int}
     */
    public function run(?int $maxDetails = null): array
    {
        if (! $this->config->enabled() || ! $this->config->hasTicket()) {
            return ['skipped' => 'Mercado Público deshabilitado o sin ticket.'];
        }
        $searches = SavedPublicSearch::withoutTenantScope()->where('is_active', true)->get()
            ->filter(fn (SavedPublicSearch $s) => in_array(PublicProcurementOpportunity::TYPE_LICITACION, self::sourceTypes($s->criteria), true))
            ->values();
        if ($searches->isEmpty()) {
            return ['skipped' => 'Ninguna búsqueda activa incluye licitaciones.'];
        }

        $list = $this->tenders->search(new V1SearchQuery(status: 'activas'), refresh: true);

        $now = CarbonImmutable::now();
        $open = PublicProcurementOpportunity::query()
            ->where('source_system', PublicProcurementOpportunity::SOURCE_SYSTEM)
            ->where('source_type', PublicProcurementOpportunity::TYPE_LICITACION)
            ->where('status_code', self::OPEN_STATUS)
            ->where('closes_at', '>', $now)
            ->whereNull('detail_fetched_at')
            ->orderByDesc('id')
            ->get(['id', 'external_code', 'title']);

        $candidates = $this->candidates($open->all(), $searches->all());
        $max = $maxDetails ?? (int) config('mercado_publico.matching.tender_details_per_run', 40);
        $enriched = 0;
        $failed = 0;
        foreach (array_slice($candidates, 0, $max) as $code) {
            try {
                $this->tenders->detail($code);
                $enriched++;
            } catch (MercadoPublicoException $e) {
                $failed++;
                if (in_array($e->errorCode, [MercadoPublicoException::QUOTA_EXHAUSTED, MercadoPublicoException::UNAUTHORIZED], true)) {
                    break; // no insistir: cuota agotada o ticket rechazado
                }
            }
        }

        return ['listed' => count($list['codes']), 'candidates' => count($candidates), 'enriched' => $enriched, 'failed' => $failed];
    }

    /** Tipos de proceso de una búsqueda (por compatibilidad, sin el dato es solo Compra Ágil). */
    public static function sourceTypes(array $criteria): array
    {
        return $criteria['source_types'] ?? [PublicProcurementOpportunity::TYPE_COMPRA_AGIL];
    }

    /**
     * Códigos a enriquecer, en orden: primero los que calzan por nombre con palabras o catálogo de alguna búsqueda;
     * luego (si hay búsquedas solo por región/monto/organismo, que necesitan el detalle) los más recientes.
     *
     * @param  list<PublicProcurementOpportunity>  $open
     * @param  list<SavedPublicSearch>  $searches
     * @return list<string>
     */
    private function candidates(array $open, array $searches): array
    {
        $priority = [];
        $needsAny = false;
        foreach ($searches as $s) {
            $c = $s->criteria;
            $words = WordMatcher::words($c['q'] ?? null);
            $catalog = ! empty($c['use_catalog']) ? $this->catalogFor($s->tenant_id) : [];
            if ($words === [] && $catalog === []) {
                $needsAny = true;

                continue;
            }
            foreach ($open as $o) {
                $titleWords = WordMatcher::words($o->title);
                // Palabras: todas deben estar en el nombre (aún no hay descripción ni productos).
                $byWords = $words !== [] && array_diff($words, $titleWords) === [];
                $byCatalog = $catalog !== [] && WordMatcher::match([$o->title], $catalog) !== [];
                if (($words === [] || $byWords) && ($catalog === [] || $byCatalog)) {
                    $priority[$o->external_code] = true;
                }
            }
        }
        $codes = array_keys($priority);
        if ($needsAny) {
            foreach ($open as $o) {
                if (! isset($priority[$o->external_code])) {
                    $codes[] = $o->external_code;
                }
            }
        }

        return $codes;
    }

    /** @return list<array{name: string, text: string}> */
    private function catalogFor(int $tenantId): array
    {
        $previous = $this->current->get();
        $this->current->set(Tenant::query()->find($tenantId));
        try {
            return Product::query()->where('is_active', true)->where('kind', 'PRODUCT')->with('category:id,name')->limit(500)->get(['id', 'name', 'category_id'])
                ->map(fn (Product $p) => ['name' => $p->name, 'text' => $p->name.' '.($p->category?->name ?? '')])->all();
        } finally {
            $this->current->set($previous);
        }
    }
}
