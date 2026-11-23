<?php

namespace App\Http\Requests\PublicProcurement;

use App\Domain\PublicProcurement\CompraAgilStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompraAgilIndexRequest extends FormRequest
{
    public const SORTABLE = ['published_at', 'closes_at', 'source_updated_at', 'available_amount_clp'];

    public function authorize(): bool
    {
        return true; // Autorización por middleware de permiso.
    }

    protected function prepareForValidation(): void
    {
        // Acepta listas separadas por coma (?status=publicada,cerrada&region=13,5).
        foreach (['status', 'region'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => array_values(array_filter(explode(',', $this->input($key)), fn ($v) => $v !== ''))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'array', 'max:6'],
            'status.*' => ['string', Rule::in(CompraAgilStatus::values())],
            'region' => ['nullable', 'array', 'max:16'],
            'region.*' => ['integer', 'between:1,16'],
            'buyer_rut' => ['nullable', 'string', 'max:16', 'regex:/^[0-9.]+-[0-9kK]$/'],
            'closes_from' => ['nullable', 'date'],
            'closes_to' => ['nullable', 'date', 'after_or_equal:closes_from'],
            'open_only' => ['nullable', 'boolean'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0'],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.*.in' => 'Estado de Compra Ágil no válido.',
            'region.*.between' => 'La región debe estar entre 1 y 16.',
            'buyer_rut.regex' => 'El RUT debe incluir guion y dígito verificador.',
        ];
    }
}
