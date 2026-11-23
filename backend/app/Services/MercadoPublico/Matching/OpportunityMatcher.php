<?php

namespace App\Services\MercadoPublico\Matching;

use App\Models\Product;
use App\Models\PublicAlert;
use App\Models\PublicOpportunityMatch;
use App\Models\PublicProcurementOpportunity;
use App\Models\SavedPublicSearch;
use App\Support\WordMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Matching preliminar entre las oportunidades ya sincronizadas (datos oficiales en la
 * base local, sin consumir cuota de Mercado Público) y las búsquedas guardadas de
 * una empresa. Requiere el tenant activo: las búsquedas, coincidencias y alertas son privadas.
 */
final class OpportunityMatcher
{
    /** Tope de oportunidades evaluadas por búsqueda y ejecución. */
    public const MAX_CANDIDATES = 500;

    /** Sin "solo abiertas", se miran las oportunidades publicadas en esta ventana. */
    public const LOOKBACK_DAYS = 60;

    public const CLOSING_SOON_HOURS = 24;

    /**
     * @return array{new: int, total: int, catalog_empty: bool}
     */
    public function run(SavedPublicSearch $search, bool $notify): array
    {
        $c = $search->criteria;
        $now = CarbonImmutable::now();
        $catalog = [];
        if (! empty($c['use_catalog'])) {
            $catalog = Product::query()->where('is_active', true)->where('kind', 'PRODUCT')->with('category:id,name')
                ->limit(500)->get(['id', 'name', 'category_id'])
                ->map(fn (Product $p) => ['name' => $p->name, 'text' => $p->name.' '.($p->category?->name ?? '')])->all();
            if ($catalog === []) {
                $search->update(['last_run_at' => $now, 'last_match_count' => $search->matches()->count()]);

                return ['new' => 0, 'total' => $search->matches()->count(), 'catalog_empty' => true];
            }
        }

        $words = WordMatcher::words($c['q'] ?? null);
        $candidates = $this->query($c, $words, $now)->with('items:id,opportunity_id,name')->limit(self::MAX_CANDIDATES)->get();

        $newIds = [];
        DB::transaction(function () use ($candidates, $c, $words, $catalog, $search, $now, &$newIds): void {
            foreach ($candidates as $o) {
                $reasons = $this->reasons($o, $c, $words, $catalog);
                if ($reasons === null) {
                    continue;
                }
                $match = PublicOpportunityMatch::query()->where('saved_search_id', $search->getKey())->where('opportunity_id', $o->getKey())->first();
                if ($match) {
                    $match->update(['reasons' => $reasons, 'last_matched_at' => $now]);
                } else {
                    PublicOpportunityMatch::create([
                        'saved_search_id' => $search->getKey(), 'opportunity_id' => $o->getKey(), 'reasons' => $reasons,
                        'status' => 'NEW', 'first_matched_at' => $now, 'last_matched_at' => $now,
                    ]);
                    $newIds[] = $o->external_code;
                }
            }
        });

        $total = $search->matches()->count();
        $search->update(['last_run_at' => $now, 'last_match_count' => $total]);

        if ($notify && $search->alerts_enabled && $newIds !== []) {
            sort($newIds);
            $n = count($newIds);
            PublicAlert::query()->firstOrCreate(
                ['dedupe_key' => 'new:'.$search->getKey().':'.sha1(implode(',', $newIds))],
                [
                    'type' => 'NEW_MATCHES',
                    'saved_search_id' => $search->getKey(),
                    'title' => ($n === 1 ? '1 oportunidad nueva' : "{$n} oportunidades nuevas").' para "'.$search->name.'"',
                    'body' => 'Códigos: '.implode(', ', array_slice($newIds, 0, 8)).($n > 8 ? ' y otros '.($n - 8) : '').'.',
                    'payload' => ['codes' => array_slice($newIds, 0, 50), 'count' => $n],
                ],
            );
        }

        return ['new' => count($newIds), 'total' => $total, 'catalog_empty' => false];
    }

    /** Alertas de cierre próximo para coincidencias vigentes de la empresa activa. */
    public function closingSoon(): int
    {
        $now = CarbonImmutable::now();
        $created = 0;
        $matches = PublicOpportunityMatch::query()
            ->whereIn('status', ['NEW', 'SEEN'])
            ->whereHas('search', fn ($q) => $q->where('is_active', true)->where('alerts_enabled', true))
            ->whereHas('opportunity', fn ($q) => $q->where(fn ($w) => self::whereOpen($w))
                ->where('closes_at', '>', $now)->where('closes_at', '<=', $now->addHours(self::CLOSING_SOON_HOURS)))
            ->with(['opportunity:id,source_type,external_code,title,closes_at', 'search:id,name'])
            ->get()
            ->unique('opportunity_id');

        foreach ($matches as $m) {
            $o = $m->opportunity;
            $alert = PublicAlert::query()->firstOrCreate(
                ['dedupe_key' => 'closing:'.$o->getKey()],
                [
                    'type' => 'CLOSING_SOON',
                    'saved_search_id' => $m->saved_search_id,
                    'opportunity_id' => $o->getKey(),
                    'title' => 'Cierra pronto: '.$o->external_code,
                    'body' => mb_strimwidth($o->title, 0, 200, '…').'. Cierre: '.$o->closes_at->setTimezone('America/Santiago')->format('d-m-Y H:i').' (hora de Chile).',
                    'payload' => ['codes' => [$o->external_code], 'source_type' => $o->source_type, 'search' => $m->search?->name],
                ],
            );
            $created += $alert->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    /** Abierta a ofertas: Compra Ágil "publicada" o licitación con estado 5 (Publicada). */
    public static function whereOpen(Builder $q): Builder
    {
        return $q->where(fn ($a) => $a->where('source_type', PublicProcurementOpportunity::TYPE_COMPRA_AGIL)->where('status_code', 'publicada'))
            ->orWhere(fn ($b) => $b->where('source_type', PublicProcurementOpportunity::TYPE_LICITACION)->where('status_code', TenderWatchService::OPEN_STATUS));
    }

    /** @param list<string> $words */
    private function query(array $c, array $words, CarbonImmutable $now): Builder
    {
        $q = PublicProcurementOpportunity::query()
            ->where('source_system', PublicProcurementOpportunity::SOURCE_SYSTEM)
            ->whereIn('source_type', TenderWatchService::sourceTypes($c));

        if ($c['open_only'] ?? true) {
            $q->where(fn ($w) => self::whereOpen($w))->where('closes_at', '>', $now);
        } else {
            $q->where(fn ($w) => $w->whereNull('published_at')->orWhere('published_at', '>=', $now->subDays(self::LOOKBACK_DAYS)));
        }
        if (! empty($c['regions'])) {
            $q->whereIn('region_code', array_map('intval', $c['regions']));
        }
        if (isset($c['min_amount'])) {
            $q->where('available_amount_clp', '>=', $c['min_amount']);
        }
        if (isset($c['max_amount'])) {
            $q->where('available_amount_clp', '<=', $c['max_amount']);
        }
        if (! empty($c['buyer_rut'])) {
            $q->where('buyer_rut', $c['buyer_rut']);
        }
        // Cada palabra debe aparecer en el nombre, descripción, organismo o algún producto solicitado.
        foreach ($words as $word) {
            $like = '%'.addcslashes($word, '%_\\').'%';
            $q->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('description', 'like', $like)->orWhere('buyer_name', 'like', $like)
                ->orWhereHas('items', fn ($i) => $i->where('name', 'like', $like)));
        }

        return $q->orderByDesc('published_at')->orderByDesc('id');
    }

    /**
     * Motivo explicable de la coincidencia; null si no coincide con el catálogo cuando se pide.
     *
     * @param  list<string>  $words
     */
    private function reasons(PublicProcurementOpportunity $o, array $c, array $words, array $catalog): ?array
    {
        $reasons = [];
        if ($words !== []) {
            $reasons['words'] = $words;
        }
        if ($catalog !== []) {
            $requested = array_values(array_filter([$o->title, ...$o->items->pluck('name')->all()]));
            $hits = WordMatcher::match($requested, $catalog, 3);
            if ($hits === []) {
                return null;
            }
            $reasons['catalog'] = array_map(fn ($h) => ['requested' => $h['requested'], 'catalog' => $h['catalog']], array_slice($hits, 0, 5));
        }
        if (! empty($c['regions'])) {
            $reasons['region'] = $o->region_name;
        }
        if (isset($c['min_amount']) || isset($c['max_amount'])) {
            $reasons['amount_clp'] = $o->available_amount_clp !== null ? (string) $o->available_amount_clp : null;
        }

        return $reasons;
    }
}
