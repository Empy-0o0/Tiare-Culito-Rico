<?php

namespace App\Http\Requests\PublicProcurement;

use App\Domain\PublicProcurement\MercadoPublicoV1Catalog;
use App\Services\MercadoPublico\DTO\V1SearchQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Listado de licitaciones u órdenes de compra.
 * - Filtros locales (q, status, sort…) operan sobre lo ya guardado.
 * - Parámetros de consulta a Mercado Público (date, source_status, buyer_code, supplier_code, fetch)
 *   disparan una consulta V1 (o usan su caché) y restringen el resultado a esa consulta.
 */
abstract class V1IndexRequest extends FormRequest
{
    /** @return list<string> */
    abstract protected function sortable(): array;

    /** @return array<string,string> */
    abstract protected function sourceStatusFilters(): array;

    /** @return array<int,string> */
    abstract protected function statusCodes(): array;

    public function authorize(): bool
    {
        return true; // Autorización por middleware de permiso.
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'integer', Rule::in(array_keys($this->statusCodes()))],
            'sort' => ['nullable', Rule::in($this->sortable())],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],

            'fetch' => ['nullable', 'boolean'],
            'refresh' => ['nullable', 'boolean'],
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:2009-12-31'],
            'source_status' => ['nullable', Rule::in(array_keys($this->sourceStatusFilters()))],
            'buyer_code' => ['nullable', 'regex:/^\d{1,12}$/'],
            'supplier_code' => ['nullable', 'regex:/^\d{1,12}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.before_or_equal' => 'La fecha de consulta no puede ser futura.',
            'buyer_code.regex' => 'El código de organismo es numérico.',
            'supplier_code.regex' => 'El código de proveedor es numérico.',
        ];
    }

    /** Consulta a Mercado Público solicitada, o null si solo se filtra lo guardado. */
    public function sourceQuery(): ?V1SearchQuery
    {
        $v = $this->validated();
        $wants = $this->boolean('fetch') || array_filter([
            $v['date'] ?? null, $v['source_status'] ?? null, $v['buyer_code'] ?? null, $v['supplier_code'] ?? null,
        ]) !== [];
        if (! $wants) {
            return null;
        }

        try {
            return new V1SearchQuery(
                date: isset($v['date']) ? CarbonImmutable::createFromFormat('Y-m-d', $v['date'], 'America/Santiago')->startOfDay() : null,
                status: $v['source_status'] ?? null,
                buyerCode: $v['buyer_code'] ?? null,
                supplierCode: $v['supplier_code'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['source_status' => [$e->getMessage()]]);
        }
    }

    public static function tenderFilters(): array
    {
        return MercadoPublicoV1Catalog::TENDER_STATUS_FILTERS;
    }
}
