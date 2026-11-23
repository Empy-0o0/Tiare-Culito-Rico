<?php

namespace App\Services\Admin;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Company\MembershipService;
use App\Support\ChileanRut;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alta de empresas clientes y gestión de su plan, exclusiva del rol administrador de TIARE (TD-024).
 * El administrador de TIARE no queda como miembro de la empresa: la empresa recibe su propio
 * administrador (rol tenant_admin), que solo ve su empresa y su plan.
 */
class TenantAdministrationService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MembershipService $memberships,
    ) {}

    /**
     * @param  array{name: string, legal_name?: ?string, rut?: ?string, plan_key: string, starts_on?: ?string, ends_on?: ?string, admin_name: string, admin_email: string}  $data
     * @return array{tenant: Tenant, link_sent: bool, new_account: bool}
     */
    public function createTenant(array $data, User $actor): array
    {
        $rut = ! empty($data['rut']) ? ChileanRut::format($data['rut']) : null;
        if ($rut && Tenant::query()->where('rut', $rut)->exists()) {
            throw ValidationException::withMessages(['rut' => 'Otra empresa ya usa este RUT.']);
        }

        $tenant = DB::transaction(function () use ($data, $rut, $actor) {
            $tenant = Tenant::create([
                'name' => trim($data['name']),
                'legal_name' => $data['legal_name'] ?? null,
                'rut' => $rut,
                'status' => 'ACTIVE',
                'is_demo' => false,
            ]);
            $this->subscribe($tenant, $data['plan_key'], $data['starts_on'] ?? null, $data['ends_on'] ?? null, null, $actor, audit: false);
            $this->audit->log('admin.tenant_created', $actor->getKey(), 'tenant', $tenant->public_id, ['plan' => $data['plan_key']]);

            return $tenant;
        });

        // Invitación del administrador de la empresa (correo para definir contraseña; TIARE nunca la ve).
        $invite = $this->memberships->invite($tenant, $data['admin_name'], $data['admin_email'], MembershipService::ADMIN_ROLE, $actor);

        return ['tenant' => $tenant, 'link_sent' => $invite['link_sent'], 'new_account' => $invite['created']];
    }

    /** Asigna un nuevo plan: termina las suscripciones activas y crea la nueva. */
    public function subscribe(Tenant $tenant, string $planKey, ?string $startsOn, ?string $endsOn, ?string $notes, User $actor, bool $audit = true): TenantSubscription
    {
        $plan = Plan::query()->where('key', $planKey)->where('is_active', true)->first();
        if (! $plan) {
            throw ValidationException::withMessages(['plan_key' => 'Plan no disponible.']);
        }
        $start = $startsOn ? Carbon::parse($startsOn, 'America/Santiago')->startOfDay() : Carbon::now('America/Santiago')->startOfDay();
        if ($endsOn && Carbon::parse($endsOn, 'America/Santiago')->lt($start)) {
            throw ValidationException::withMessages(['ends_on' => 'El término debe ser posterior al inicio.']);
        }

        return DB::transaction(function () use ($tenant, $plan, $start, $endsOn, $notes, $actor, $audit) {
            $previous = $tenant->subscriptions()->where('status', 'active')->with('plan')->get();
            foreach ($previous as $sub) {
                $sub->forceFill([
                    'status' => 'cancelled',
                    'ends_on' => $sub->starts_on->lt($start) ? $start->copy()->subDay()->toDateString() : $sub->starts_on->toDateString(),
                ])->save();
            }

            $subscription = $tenant->subscriptions()->create([
                'plan_id' => $plan->getKey(),
                'status' => 'active',
                'starts_on' => $start->toDateString(),
                'ends_on' => $endsOn,
                'notes' => $notes,
                'created_by_user_id' => $actor->getKey(),
            ]);
            $tenant->forgetSubscriptionCache();

            if ($audit) {
                $this->audit->log('admin.subscription_changed', $actor->getKey(), 'tenant', $tenant->public_id, [
                    'plan_from' => $previous->first()?->plan?->key,
                    'plan_to' => $plan->key,
                    'starts_on' => $start->toDateString(),
                    'ends_on' => $endsOn,
                ]);
            }

            return $subscription;
        });
    }

    public function setStatus(Tenant $tenant, string $status, User $actor): void
    {
        if ($tenant->status === $status) {
            return;
        }
        $from = $tenant->status;
        $tenant->forceFill(['status' => $status])->save();
        $this->audit->log('admin.tenant_status_changed', $actor->getKey(), 'tenant', $tenant->public_id, ['from' => $from, 'to' => $status]);
    }
}
