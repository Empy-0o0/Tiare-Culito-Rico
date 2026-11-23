<?php

namespace App\Http\Requests\Crm;

use App\Domain\Crm\OrganizationKind;
use App\Models\Category;
use App\Rules\ValidChileanRut;
use App\Support\CurrentTenant;
use Illuminate\Validation\Rule;

/** Reglas compartidas para datos de organización y contacto. */
final class OrganizationRules
{
    /** @return array<string, mixed> */
    public static function organization(string $prefix, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            $prefix => [$creating ? 'required' : 'sometimes', 'array'],
            "{$prefix}.legal_name" => [$required, 'string', 'min:2', 'max:200'],
            "{$prefix}.trade_name" => ['nullable', 'string', 'max:200'],
            "{$prefix}.rut" => ['nullable', 'string', 'max:14', new ValidChileanRut],
            "{$prefix}.kind" => [$required, Rule::enum(OrganizationKind::class)],
            "{$prefix}.segment" => ['nullable', 'string', 'max:80'],
            "{$prefix}.email" => ['nullable', 'email:rfc', 'max:190'],
            "{$prefix}.phone" => ['nullable', 'string', 'max:40', 'regex:/^[0-9+\s()\-]{6,40}$/'],
            "{$prefix}.website" => ['nullable', 'url:https,http', 'max:190'],
            "{$prefix}.address" => ['nullable', 'string', 'max:255'],
            "{$prefix}.commune" => ['nullable', 'string', 'max:80'],
            "{$prefix}.region_code" => ['nullable', 'integer', 'between:1,16'],
            "{$prefix}.notes" => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, mixed> */
    public static function contact(string $prefix, bool $required): array
    {
        return [
            $prefix => ['nullable', 'array'],
            "{$prefix}.full_name" => [$required ? 'required' : 'nullable', 'string', 'min:2', 'max:160'],
            "{$prefix}.position" => ['nullable', 'string', 'max:120'],
            "{$prefix}.email" => ['nullable', 'email:rfc', 'max:190'],
            "{$prefix}.phone" => ['nullable', 'string', 'max:40', 'regex:/^[0-9+\s()\-]{6,40}$/'],
            "{$prefix}.is_primary" => ['nullable', 'boolean'],
        ];
    }

    /** Categorías válidas solo del tenant activo (por public_id). @return array<string, mixed> */
    public static function categories(): array
    {
        return [
            'category_public_ids' => ['sometimes', 'array', 'max:30'],
            'category_public_ids.*' => ['string', 'size:26', Rule::exists('categories', 'public_id')->where('tenant_id', app(CurrentTenant::class)->id())],
        ];
    }

    /** @param list<string>|null $publicIds @return list<int> */
    public static function categoryIds(?array $publicIds): array
    {
        return $publicIds ? Category::query()->whereIn('public_id', $publicIds)->pluck('id')->all() : [];
    }
}
