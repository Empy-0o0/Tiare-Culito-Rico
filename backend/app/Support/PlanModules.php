<?php

namespace App\Support;

/**
 * Módulos que un plan puede habilitar y los permisos que cada uno cubre (TD-024).
 *
 * Los permisos de un rol de empresa se cruzan con los módulos del plan vigente: si el plan
 * no incluye un módulo, ningún usuario de esa empresa obtiene sus permisos, aunque su rol los tenga.
 * Los permisos CORE (empresa, usuarios, tokens) no dependen del plan.
 */
final class PlanModules
{
    /** módulo => [nombre, prefijos de permiso] */
    public const MODULES = [
        'public_procurement' => ['Mercado Público', ['public_procurement.']],
        'procurement' => ['Compras privadas', ['purchase_requests.', 'quotations.', 'purchase_orders.', 'invoices.']],
        'suppliers' => ['Proveedores', ['suppliers.', 'portal.supplier']],
        'crm' => ['Clientes y contactos', ['customers.', 'portal.customer']],
        'catalog' => ['Catálogo', ['products.']],
        'inventory' => ['Inventario', ['inventory.']],
        'logistics' => ['Logística', ['shipments.']],
        'agents' => ['Agentes IA', ['agents.']],
        'reports' => ['Analítica e informes', ['reports.']],
    ];

    /** Permisos que no dependen del plan. */
    public const CORE_PREFIXES = ['company.', 'users.', 'api_tokens.'];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::MODULES);
    }

    public static function label(string $module): string
    {
        return self::MODULES[$module][0] ?? $module;
    }

    /**
     * Filtra permisos de rol de empresa según los módulos del plan.
     *
     * @param  list<string>  $permissions
     * @param  list<string>  $modules
     * @return list<string>
     */
    public static function filter(array $permissions, array $modules): array
    {
        $prefixes = self::CORE_PREFIXES;
        foreach ($modules as $module) {
            $prefixes = array_merge($prefixes, self::MODULES[$module][1] ?? []);
        }

        return array_values(array_filter($permissions, function (string $perm) use ($prefixes): bool {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($perm, $prefix)) {
                    return true;
                }
            }

            return false;
        }));
    }
}
