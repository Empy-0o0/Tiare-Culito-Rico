<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Rules\ValidChileanRut;
use App\Services\Admin\TenantAdministrationService;
use App\Support\ApiResponse;
use App\Support\PlanModules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Administración TIARE: empresas clientes, planes y suscripciones.
 * Protegido por el permiso de plataforma platform.tenants.manage (ningún rol de empresa lo tiene).
 */
class AdminTenantController extends Controller
{
    public function __construct(private readonly TenantAdministrationService $service) {}

    public function plans(): JsonResponse
    {
        $plans = Plan::query()->where('is_active', true)->orderBy('id')->get()->map(fn (Plan $p) => $p->toSummary())->values();

        return ApiResponse::ok($plans, [
            'modules' => collect(PlanModules::keys())->map(fn ($k) => ['key' => $k, 'name' => PlanModules::label($k)])->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'SUSPENDED'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = Tenant::query()
            ->when($v['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('name', 'like', '%'.addcslashes($term, '%_\\').'%')->orWhere('rut', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->when($v['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('name')
            ->paginate(20);

        return ApiResponse::paginated($page, collect($page->items())->map(fn (Tenant $t) => $this->present($t))->values());
    }

    public function show(string $publicId): JsonResponse
    {
        $tenant = $this->find($publicId);
        $roles = Role::query()->where('scope', 'tenant')->pluck('name', 'id');

        return ApiResponse::ok($this->present($tenant) + [
            'users' => $tenant->users()->orderBy('users.name')->get()->map(fn (User $u) => [
                'public_id' => $u->public_id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $roles[$u->pivot->role_id] ?? null,
                'is_active' => (bool) $u->pivot->is_active && $u->is_active,
                'last_login_at' => $u->last_login_at?->toIso8601String(),
            ])->values(),
            'subscriptions' => $tenant->subscriptions()->with('plan')->latest('starts_on')->latest('id')->get()->map(fn (TenantSubscription $s) => $s->toSummary())->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'rut' => ['nullable', 'string', 'max:14', new ValidChileanRut],
            'plan_key' => ['required', 'string', Rule::exists('plans', 'key')->where('is_active', true)],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'admin_name' => ['required', 'string', 'min:2', 'max:120'],
            'admin_email' => ['required', 'email:rfc', 'max:190'],
        ]);

        $result = $this->service->createTenant($v, $request->user());

        return ApiResponse::ok($this->present($result['tenant']->fresh()) + [
            'message' => $result['new_account']
                ? 'Empresa creada. El administrador de la empresa recibirá un correo para definir su contraseña.'
                : 'Empresa creada. La persona ya tenía cuenta en TIARE y ahora administra esta empresa.',
            'link_sent' => $result['link_sent'],
        ], status: 201);
    }

    public function update(Request $request, string $publicId): JsonResponse
    {
        $tenant = $this->find($publicId);
        $v = $request->validate(['status' => ['required', Rule::in(['ACTIVE', 'SUSPENDED'])]]);
        $this->service->setStatus($tenant, $v['status'], $request->user());

        return ApiResponse::ok($this->present($tenant->fresh()));
    }

    public function subscribe(Request $request, string $publicId): JsonResponse
    {
        $tenant = $this->find($publicId);
        $v = $request->validate([
            'plan_key' => ['required', 'string', Rule::exists('plans', 'key')->where('is_active', true)],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $this->service->subscribe($tenant, $v['plan_key'], $v['starts_on'] ?? null, $v['ends_on'] ?? null, $v['notes'] ?? null, $request->user());

        return ApiResponse::ok($this->present($tenant->fresh()));
    }

    private function find(string $publicId): Tenant
    {
        $tenant = Tenant::query()->where('public_id', $publicId)->first();
        abort_if($tenant === null, 404);

        return $tenant;
    }

    /** @return array<string, mixed> */
    private function present(Tenant $t): array
    {
        $sub = $t->currentSubscription();

        return [
            'public_id' => $t->public_id,
            'name' => $t->name,
            'legal_name' => $t->legal_name,
            'rut' => $t->rut,
            'status' => $t->status,
            'is_demo' => $t->is_demo,
            'subscription' => $sub?->toSummary(),
            'seats' => ['used' => $t->activeMembersCount(), 'max' => $sub?->plan?->max_users],
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }
}
