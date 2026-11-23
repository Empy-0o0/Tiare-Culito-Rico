<?php

namespace App\Http\Requests\Crm;

use App\Domain\Crm\CustomerStatus;
use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CustomerRequest extends FormRequest
{
    /** Autoriza antes de validar: un usuario sin permiso recibe 403, no detalles de validación. */
    public function authorize(): bool
    {
        $customer = $this->route('customer');

        return $customer instanceof Customer
            ? Gate::allows('update', $customer)
            : Gate::allows('create', Customer::class);
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return array_merge(
            OrganizationRules::organization('organization', $creating),
            OrganizationRules::contact('contact', false),
            [
                // Acceso al portal al crear (TD-028): se crea su usuario con contraseña de inicio.
                'portal_access' => ['sometimes', 'nullable', 'array'],
                'portal_access.email' => ['required_with:portal_access', 'email:rfc', 'max:190'],
                'portal_access.name' => ['nullable', 'string', 'min:2', 'max:120'],
                'status' => ['sometimes', Rule::enum(CustomerStatus::class)],
                'plan' => ['nullable', Rule::in(['Start', 'Growth', 'Managed', 'Public', 'SaaS'])],
                'source' => ['nullable', 'string', 'max:80'],
                'next_action_at' => ['nullable', 'date_format:Y-m-d'],
                'next_action_summary' => ['nullable', 'string', 'max:255'],
            ],
        );
    }
}
