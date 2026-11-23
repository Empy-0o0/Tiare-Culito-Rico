<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\PublicProcurement\ChileRegion;
use App\Http\Controllers\Controller;
use App\Models\PublicAlert;
use App\Models\PublicAlertRead;
use App\Models\PublicOpportunityMatch;
use App\Models\PublicProcurementOpportunity;
use App\Models\SavedPublicSearch;
use App\Services\Audit\AuditLogger;
use App\Services\MercadoPublico\Matching\OpportunityMatcher;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Búsquedas guardadas, coincidencias y alertas de oportunidades públicas (CLAUDE.md §5.5).
 * Privado por empresa; requiere public_procurement.read. Trabaja sobre datos ya
 * sincronizados: no consulta Mercado Público ni consume cuota.
 */
class SavedSearchController extends Controller
{
    public function __construct(private readonly OpportunityMatcher $matcher, private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $rows = SavedPublicSearch::query()
            ->withCount(['matches', 'matches as new_count' => fn ($q) => $q->where('status', 'NEW')])
            ->orderByDesc('is_active')->orderBy('name')->get();

        return ApiResponse::ok($rows->map(fn ($s) => $this->search($s))->values(), [
            'max_searches' => (int) config('mercado_publico.matching.max_searches_per_tenant'),
            'scheduler_enabled' => (bool) config('mercado_publico.matching.enabled', true),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $this->validated($request, null);
        $max = (int) config('mercado_publico.matching.max_searches_per_tenant');
        if (SavedPublicSearch::query()->where('is_active', true)->count() >= $max) {
            throw ValidationException::withMessages(['name' => "Tu empresa ya tiene {$max} búsquedas activas. Desactiva alguna para crear otra."]);
        }
        $search = SavedPublicSearch::create($v + ['created_by' => $request->user()->getKey()]);
        $result = $this->matcher->run($search, notify: false);
        $this->audit->log('public_procurement.search_created', $request->user()->getKey(), 'saved_public_search', $search->public_id);

        return ApiResponse::ok($this->search($this->reload($search)), ['run' => $result], 201);
    }

    public function update(Request $request, SavedPublicSearch $savedSearch): JsonResponse
    {
        $v = $this->validated($request, $savedSearch);
        $criteriaChanged = isset($v['criteria']) && $v['criteria'] !== $savedSearch->criteria;
        $savedSearch->update($v);
        $result = $criteriaChanged && $savedSearch->is_active ? $this->matcher->run($savedSearch, notify: false) : null;
        $this->audit->log('public_procurement.search_updated', $request->user()->getKey(), 'saved_public_search', $savedSearch->public_id, ['fields' => array_keys($v)]);

        return ApiResponse::ok($this->search($this->reload($savedSearch)), $result ? ['run' => $result] : []);
    }

    public function run(SavedPublicSearch $savedSearch): JsonResponse
    {
        if (! $savedSearch->is_active) {
            return ApiResponse::error('UNPROCESSABLE', 'La búsqueda está desactivada.', 422);
        }
        $result = $this->matcher->run($savedSearch, notify: false);

        return ApiResponse::ok($this->search($this->reload($savedSearch)), ['run' => $result]);
    }

    public function matches(Request $request): JsonResponse
    {
        $v = $request->validate([
            'search' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', Rule::in(array_keys(PublicOpportunityMatch::STATUSES))],
            'open_only' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);
        $page = PublicOpportunityMatch::query()
            ->with(['opportunity', 'search:id,public_id,name'])
            ->when($v['search'] ?? null, fn ($q, $s) => $q->whereHas('search', fn ($w) => $w->where('public_id', $s)))
            ->when($v['status'] ?? null, fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->where('status', '!=', 'DISMISSED'))
            ->when($request->boolean('open_only'), fn ($q) => $q->whereHas('opportunity', fn ($o) => $o->where('status_code', 'publicada')->where('closes_at', '>', now())))
            ->orderByRaw("CASE WHEN status = 'NEW' THEN 0 ELSE 1 END")
            ->orderByDesc('first_matched_at')->orderByDesc('id')
            ->paginate((int) ($v['per_page'] ?? 20));

        return ApiResponse::paginated($page, collect($page->items())->map(fn ($m) => $this->match($m))->values(), [
            'counts' => PublicOpportunityMatch::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status'),
        ]);
    }

    public function updateMatch(Request $request, PublicOpportunityMatch $match): JsonResponse
    {
        $v = $request->validate(['status' => ['required', Rule::in(array_keys(PublicOpportunityMatch::STATUSES))]]);
        $match->update(['status' => $v['status']]);

        return ApiResponse::ok($this->match($match->load(['opportunity', 'search:id,public_id,name'])));
    }

    public function alerts(Request $request): JsonResponse
    {
        $userId = $request->user()->getKey();
        $alerts = PublicAlert::query()
            ->withExists(['reads as read' => fn ($q) => $q->where('user_id', $userId)])
            ->with('search:id,public_id,name')
            ->latest('id')->limit(30)->get();
        $unread = PublicAlert::query()->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $userId))->count();

        return ApiResponse::ok($alerts->map(fn (PublicAlert $a) => [
            'public_id' => $a->public_id,
            'type' => ['code' => $a->type, 'label' => PublicAlert::TYPES[$a->type] ?? $a->type],
            'title' => $a->title,
            'body' => $a->body,
            'codes' => $a->payload['codes'] ?? [],
            'source_type' => $a->payload['source_type'] ?? null,
            'search' => $a->search ? ['public_id' => $a->search->public_id, 'name' => $a->search->name] : null,
            'read' => (bool) $a->read,
            'created_at' => $a->created_at?->toIso8601String(),
        ])->values(), ['unread_count' => $unread]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $v = $request->validate(['alert_public_ids' => ['nullable', 'array', 'max:100'], 'alert_public_ids.*' => ['string', 'size:26']]);
        $userId = $request->user()->getKey();
        $ids = PublicAlert::query()
            ->when($v['alert_public_ids'] ?? null, fn ($q, $ids) => $q->whereIn('public_id', $ids))
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $userId))
            ->pluck('id');
        foreach ($ids as $id) {
            PublicAlertRead::query()->firstOrCreate(['alert_id' => $id, 'user_id' => $userId], ['read_at' => now()]);
        }

        return ApiResponse::ok(['marked' => $ids->count()]);
    }

    private function validated(Request $request, ?SavedPublicSearch $existing): array
    {
        $tenantId = app(\App\Support\CurrentTenant::class)->id();
        $req = $existing ? 'sometimes' : 'required';
        $v = $request->validate([
            'name' => [$req, 'string', 'min:2', 'max:120', Rule::unique('saved_public_searches', 'name')->where('tenant_id', $tenantId)->ignore($existing?->getKey())],
            'criteria' => [$req, 'array'],
            'criteria.q' => ['nullable', 'string', 'max:120'],
            'criteria.regions' => ['nullable', 'array', 'max:16'],
            'criteria.regions.*' => ['integer', Rule::in(array_keys(ChileRegion::NAMES))],
            'criteria.min_amount' => ['nullable', 'numeric', 'min:0'],
            'criteria.max_amount' => ['nullable', 'numeric', 'min:0', 'gte:criteria.min_amount'],
            'criteria.buyer_rut' => ['nullable', 'string', 'max:14', 'regex:/^[0-9.]+-[0-9kK]$/'],
            'criteria.open_only' => ['nullable', 'boolean'],
            'criteria.use_catalog' => ['nullable', 'boolean'],
            'criteria.source_types' => ['nullable', 'array', 'min:1', 'max:2'],
            'criteria.source_types.*' => [Rule::in([PublicProcurementOpportunity::TYPE_COMPRA_AGIL, PublicProcurementOpportunity::TYPE_LICITACION])],
            'alerts_enabled' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($v['criteria'])) {
            $c = $v['criteria'];
            $criteria = array_filter([
                'q' => isset($c['q']) ? trim($c['q']) : null,
                'regions' => ! empty($c['regions']) ? array_values(array_unique(array_map('intval', $c['regions']))) : null,
                'min_amount' => isset($c['min_amount']) ? (float) $c['min_amount'] : null,
                'max_amount' => isset($c['max_amount']) ? (float) $c['max_amount'] : null,
                'buyer_rut' => $c['buyer_rut'] ?? null,
            ], fn ($x) => $x !== null && $x !== '');
            $criteria['open_only'] = (bool) ($c['open_only'] ?? true);
            $criteria['use_catalog'] = (bool) ($c['use_catalog'] ?? false);
            $criteria['source_types'] = array_values(array_unique($c['source_types'] ?? [PublicProcurementOpportunity::TYPE_COMPRA_AGIL]));
            if (! isset($criteria['q']) && ! isset($criteria['regions']) && ! isset($criteria['buyer_rut']) && ! $criteria['use_catalog'] && ! isset($criteria['min_amount']) && ! isset($criteria['max_amount'])) {
                throw ValidationException::withMessages(['criteria' => 'Indica al menos un criterio: palabras, región, organismo, monto o tu catálogo.']);
            }
            $v['criteria'] = $criteria;
        }

        return $v;
    }

    private function reload(SavedPublicSearch $s): SavedPublicSearch
    {
        return SavedPublicSearch::query()->withCount(['matches', 'matches as new_count' => fn ($q) => $q->where('status', 'NEW')])->findOrFail($s->getKey());
    }

    private function search(SavedPublicSearch $s): array
    {
        $c = $s->criteria;

        return [
            'public_id' => $s->public_id,
            'name' => $s->name,
            'criteria' => $c,
            'regions' => array_map(fn ($r) => ['code' => $r, 'name' => ChileRegion::name($r)], $c['regions'] ?? []),
            'alerts_enabled' => $s->alerts_enabled,
            'is_active' => $s->is_active,
            'matches_count' => $s->matches_count ?? null,
            'new_count' => $s->new_count ?? null,
            'last_run_at' => $s->last_run_at?->toIso8601String(),
        ];
    }

    private function match(PublicOpportunityMatch $m): array
    {
        $o = $m->opportunity;

        return [
            'public_id' => $m->public_id,
            'status' => ['code' => $m->status, 'label' => PublicOpportunityMatch::STATUSES[$m->status] ?? $m->status],
            'reasons' => $m->reasons,
            'search' => ['public_id' => $m->search->public_id, 'name' => $m->search->name],
            'first_matched_at' => $m->first_matched_at?->toIso8601String(),
            'opportunity' => [
                'source_type' => $o->source_type,
                'code' => $o->external_code,
                'has_detail' => $o->detail_fetched_at !== null,
                'title' => $o->title,
                'status' => ['code' => $o->status_code, 'label' => $o->status_label],
                'buyer' => $o->buyer_name,
                'region' => $o->region_name,
                'closes_at' => $o->closes_at?->toIso8601String(),
                'available_amount_clp' => $o->available_amount_clp !== null ? (string) $o->available_amount_clp : null,
                'last_fetched_at' => ($o->detail_fetched_at ?? $o->last_fetched_at)?->toIso8601String(),
                'data_origin' => 'official',
            ],
        ];
    }
}
