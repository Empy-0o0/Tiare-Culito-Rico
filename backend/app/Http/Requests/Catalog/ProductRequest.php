<?php

namespace App\Http\Requests\Catalog;

use App\Models\Product;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    /** Autoriza antes de validar: un usuario sin permiso recibe 403, no detalles de validación. */
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product instanceof Product
            ? Gate::allows('update', $product)
            : Gate::allows('create', Product::class);
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $tenantId = app(CurrentTenant::class)->id();
        /** @var Product|null $product */
        $product = $this->route('product');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:200'],
            'kind' => [$creating ? 'required' : 'sometimes', Rule::in(Product::KINDS)],
            'unit_code' => [$creating ? 'required' : 'sometimes', 'string', Rule::exists('units_of_measure', 'code')],
            'category_public_id' => ['nullable', 'string', 'size:26', Rule::exists('categories', 'public_id')->where('tenant_id', $tenantId)],
            'sku' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\-]+$/',
                Rule::unique('products', 'sku')->where('tenant_id', $tenantId)->ignore($product?->getKey())],
            'description' => ['nullable', 'string', 'max:5000'],
            'brand' => ['nullable', 'string', 'max:120'],
            'public_catalog_code' => ['nullable', 'string', 'max:32', 'regex:/^[0-9]{6,10}$/'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'sku.unique' => 'Ya existe un producto con este SKU.',
            'public_catalog_code.regex' => 'El código de catálogo de Mercado Público debe tener entre 6 y 10 dígitos.',
        ];
    }
}
