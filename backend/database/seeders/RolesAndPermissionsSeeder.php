<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Roles y permisos base (CLAUDE.md §9). Idempotente.
 * La matriz definitiva está pendiente (AUTHORIZATION_MATRIX.md no existe; PEND-T01).
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'public_procurement.read' => 'Consultar oportunidades públicas',
        'public_procurement.sync' => 'Ejecutar sincronización con Mercado Público',
        'public_procurement.documents' => 'Subir y eliminar copias de documentos de procesos de Mercado Público',
        'purchase_requests.create' => 'Crear solicitudes de compra',
        'purchase_requests.read' => 'Ver solicitudes de compra',
        'purchase_requests.submit' => 'Enviar solicitudes de compra',
        'purchase_requests.approve' => 'Aprobar solicitudes de compra',
        'purchase_requests.reject' => 'Rechazar solicitudes de compra',
        'quotations.create' => 'Registrar cotizaciones',
        'quotations.read' => 'Ver cotizaciones',
        'quotations.compare' => 'Comparar cotizaciones',
        'quotations.delete' => 'Eliminar cotizaciones mientras la solicitud está en cotización',
        'purchase_orders.create' => 'Crear órdenes internas',
        'purchase_orders.read' => 'Ver órdenes internas',
        'purchase_orders.approve' => 'Aprobar órdenes internas',
        'purchase_orders.cancel' => 'Anular órdenes internas',
        'purchase_orders.delete' => 'Eliminar órdenes internas sin recepciones ni facturas',
        'purchase_orders.receive' => 'Registrar recepciones de órdenes internas',
        'invoices.manage' => 'Registrar facturas de proveedores y pagos informados',
        'customers.read' => 'Ver clientes y contactos',
        'customers.create' => 'Crear clientes',
        'customers.update' => 'Editar clientes y sus contactos',
        'customers.delete' => 'Eliminar clientes sin historial de compras',
        'products.read' => 'Ver catálogo de productos y servicios',
        'products.manage' => 'Administrar productos y categorías',
        'suppliers.read' => 'Ver proveedores',
        'suppliers.create' => 'Crear proveedores',
        'suppliers.update' => 'Editar proveedores',
        'suppliers.delete' => 'Eliminar proveedores sin historial de compras',
        'suppliers.evaluate' => 'Evaluar proveedores',
        'shipments.read' => 'Ver despachos',
        'shipments.update' => 'Actualizar despachos',
        'shipments.confirm_delivery' => 'Confirmar entregas',
        'inventory.read' => 'Ver bodegas, stock y movimientos',
        'inventory.manage' => 'Registrar movimientos de inventario y administrar bodegas',
        'agents.read' => 'Ver análisis de agentes IA',
        'agents.run' => 'Ejecutar agentes IA',
        'agents.review' => 'Revisar resultados de agentes IA',
        'reports.read' => 'Ver informes',
        'reports.export' => 'Exportar informes',
        'company.manage' => 'Editar los datos de la empresa',
        'users.manage' => 'Invitar usuarios y asignar roles en la empresa',
        'api_tokens.manage' => 'Emitir y revocar tokens de acceso API de la empresa',
        'portal.customer' => 'Ver el portal de su propio perfil de cliente (solo lectura)',
        'portal.supplier' => 'Ver el portal de su propio perfil de proveedor (solo lectura)',
        'platform.tenants.manage' => 'Administrar empresas clientes, planes y suscripciones (solo TIARE)',
        'platform.users.manage' => 'Administrar cuentas de usuario de toda la plataforma (solo TIARE)',
        'platform.roles.manage' => 'Crear y editar roles y sus permisos (solo TIARE)',
    ];

    /** rol => [scope, nombre, permisos ('*' = todos)] */
    public const ROLES = [
        'platform_super_admin' => ['platform', 'Superadministrador de plataforma', ['*']],
        'tiare_admin' => ['platform', 'Administrador TIARE', ['*']],
        'procurement_manager' => ['platform', 'Gestor de compras', ['public_procurement.read', 'public_procurement.documents', 'invoices.manage', 'purchase_requests.*', 'quotations.*', 'purchase_orders.*', 'suppliers.*', 'products.*', 'customers.read', 'reports.read', 'agents.*']],
        'public_procurement_analyst' => ['platform', 'Analista de compras públicas', ['public_procurement.read', 'public_procurement.sync', 'public_procurement.documents', 'customers.read', 'reports.read', 'agents.*']],
        'supplier_manager' => ['platform', 'Gestor de proveedores', ['suppliers.*', 'products.read', 'quotations.read']],
        'logistics_operator' => ['platform', 'Operador logístico', ['shipments.*', 'inventory.*', 'purchase_orders.read', 'suppliers.read']],
        'finance_analyst' => ['platform', 'Analista financiero', ['purchase_orders.read', 'invoices.manage', 'customers.read', 'reports.read', 'reports.export']],
        'tenant_admin' => ['tenant', 'Administrador de empresa', ['public_procurement.read', 'public_procurement.documents', 'customers.*', 'suppliers.*', 'products.*', 'purchase_requests.*', 'quotations.*', 'purchase_orders.*', 'invoices.manage', 'shipments.*', 'inventory.*', 'reports.read', 'reports.export', 'company.manage', 'users.manage', 'api_tokens.manage', 'agents.*']],
        'tenant_procurement_user' => ['tenant', 'Usuario de compras', ['public_procurement.read', 'public_procurement.documents', 'customers.read', 'suppliers.read', 'products.read', 'purchase_requests.create', 'purchase_requests.read', 'purchase_requests.submit', 'quotations.read', 'purchase_orders.read', 'purchase_orders.receive', 'shipments.read', 'inventory.read', 'agents.read', 'agents.run']],
        'tenant_approver' => ['tenant', 'Aprobador', ['suppliers.read', 'products.read', 'purchase_requests.read', 'purchase_requests.approve', 'purchase_requests.reject', 'quotations.read', 'quotations.compare', 'purchase_orders.read', 'agents.read', 'agents.review']],
        // Portales (TD-028): solo su propio perfil. Se asignan desde la ficha del cliente/proveedor, no como rol de equipo.
        'customer_portal' => ['tenant', 'Portal de cliente', ['portal.customer']],
        'supplier_portal' => ['tenant', 'Portal de proveedor', ['portal.supplier']],
        'tenant_read_only' => ['tenant', 'Solo lectura', ['public_procurement.read', 'customers.read', 'suppliers.read', 'products.read', 'purchase_requests.read', 'quotations.read', 'shipments.read', 'inventory.read', 'reports.read', 'agents.read']],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $key => $description) {
            Permission::query()->updateOrCreate(['key' => $key], ['description' => $description]);
        }
        $all = Permission::query()->pluck('id', 'key');

        foreach (self::ROLES as $key => [$scope, $name, $patterns]) {
            $existing = Role::withTrashed()->where('key', $key)->first();
            // Un administrador editó (TD-025) o eliminó (TD-031) este rol desde el mantenedor: no se toca.
            if ($existing?->trashed() || $existing?->permissions_customized_at !== null) {
                continue;
            }
            $role = Role::query()->updateOrCreate(['key' => $key], ['name' => $name, 'scope' => $scope]);
            $ids = $all->filter(fn ($id, $perm) => collect($patterns)->contains(
                fn ($p) => $p === '*' || $p === $perm || (str_ends_with($p, '.*') && str_starts_with($perm, substr($p, 0, -1)))
            ))->values()->all();
            $role->permissions()->sync($ids);
        }
    }
}
