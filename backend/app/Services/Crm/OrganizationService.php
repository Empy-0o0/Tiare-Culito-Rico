<?php

namespace App\Services\Crm;

use App\Models\Contact;
use App\Models\Organization;
use App\Support\ChileanRut;
use Illuminate\Validation\ValidationException;

/**
 * Alta/actualización de organizaciones dentro del tenant activo.
 * Una misma organización (mismo RUT) puede ser cliente y proveedor: se reutiliza.
 */
class OrganizationService
{
    public const FIELDS = ['legal_name', 'trade_name', 'rut', 'kind', 'segment', 'email', 'phone', 'website', 'address', 'commune', 'region_code', 'notes'];

    /** @param array<string, mixed> $data */
    public function findOrCreate(array $data, int $userId): Organization
    {
        $rut = isset($data['rut']) && $data['rut'] !== null ? ChileanRut::format($data['rut']) : null;
        if ($rut !== null) {
            $existing = Organization::query()->where('rut', $rut)->first();
            if ($existing) {
                return $existing;
            }
        }

        return Organization::create(array_merge(
            array_intersect_key($data, array_flip(self::FIELDS)),
            ['rut' => $rut, 'created_by' => $userId, 'updated_by' => $userId],
        ));
    }

    /** @param array<string, mixed> $data */
    public function update(Organization $organization, array $data, int $userId): Organization
    {
        $attributes = array_intersect_key($data, array_flip(self::FIELDS));
        if (array_key_exists('rut', $attributes)) {
            $attributes['rut'] = $attributes['rut'] !== null ? ChileanRut::format($attributes['rut']) : null;
            if ($attributes['rut'] !== null && Organization::query()->where('rut', $attributes['rut'])->whereKeyNot($organization->getKey())->exists()) {
                throw ValidationException::withMessages(['rut' => 'Ya existe otra organización con este RUT.']);
            }
        }
        $organization->fill($attributes + ['updated_by' => $userId])->save();

        return $organization;
    }

    /** @param array{full_name:string, position?:?string, email?:?string, phone?:?string, is_primary?:bool} $data */
    public function addContact(Organization $organization, array $data): Contact
    {
        $isPrimary = (bool) ($data['is_primary'] ?? ! $organization->contacts()->exists());
        if ($isPrimary) {
            $organization->contacts()->update(['is_primary' => false]);
        }

        return $organization->contacts()->create([
            'full_name' => $data['full_name'],
            'position' => $data['position'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'is_primary' => $isPrimary,
        ]);
    }
}
