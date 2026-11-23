<?php

namespace App\Http\Requests\Crm;

use App\Domain\Crm\SupplierStatus;
use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SupplierRequest extends FormRequest
{
    /** Autoriza antes de validar: un usuario sin permiso recibe 403, no detalles de validación. */
    public function authorize(): bool
    {
        $supplier = $this->route('supplier');

        return $supplier instanceof Supplier
            ? Gate::allows('update', $supplier)
            : Gate::allows('create', Supplier::class);
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return array_merge(
            OrganizationRules::organization('organization', $creating),
            OrganizationRules::contact('contact', false),
            OrganizationRules::categories(),
            [
                // Acceso al portal al crear (TD-028): se crea su usuario con contraseña de inicio.
                'portal_access' => ['sometimes', 'nullable', 'array'],
                'portal_access.email' => ['required_with:portal_access', 'email:rfc', 'max:190'],
                'portal_access.name' => ['nullable', 'string', 'min:2', 'max:120'],
                'status' => ['sometimes', Rule::enum(SupplierStatus::class)],
                'coverage_regions' => ['nullable', 'array', 'max:16'],
                'coverage_regions.*' => ['integer', 'between:1,16', 'distinct'],
                'payment_terms' => ['nullable', 'string', 'max:120'],
                'lead_time_days' => ['nullable', 'integer', 'between:0,365'],
                'notes' => ['nullable', 'string', 'max:5000'],
            ],
        );
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $data = $this->validated();
        if ($this->has('category_public_ids')) {
            $data['category_ids'] = OrganizationRules::categoryIds($data['category_public_ids'] ?? []);
        }
        unset($data['category_public_ids']);

        return $data;
    }
}
