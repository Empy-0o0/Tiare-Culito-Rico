<?php

namespace Database\Seeders;

use App\Domain\Crm\CustomerStatus;
use App\Domain\Crm\OrganizationKind;
use App\Domain\Crm\SupplierStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Support\CurrentTenant;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Datos FICTICIOS portados del prototipo, solo para el tenant demostrativo (is_demo = true).
 * - Sin RUT (para no coincidir con empresas reales) y correos en dominio reservado .test.
 * - No se portan métricas inventadas (gasto, evaluación, órdenes) del prototipo.
 */
class DemoDataSeeder extends Seeder
{
    private const CATEGORIES = ['Aseo e higiene', 'Seguridad industrial', 'Insumos clínicos', 'Oficina', 'Mantención', 'Logística', 'Vestuario'];

    private const CUSTOMERS = [
        ['Centro Médico Los Robles', 'Salud', 'María Fernanda Silva', 'Growth', 'ACTIVE'],
        ['Constructora Andes Sur', 'Construcción', 'Jorge Valdés', 'Managed', 'ACTIVE'],
        ['Colegio Nuevo Horizonte', 'Educación', 'Paula Contreras', 'Start', 'ACTIVE'],
        ['Laboratorio Clínico Vital', 'Salud', 'Andrés Molina', 'Growth', 'ACTIVE'],
        ['Suministros Técnicos del Sur', 'Proveedor Estado', 'Carolina Pérez', 'Public', 'PROSPECT'],
        ['Logística Patagonia', 'Industria', 'Matías Araya', 'Managed', 'ACTIVE'],
    ];

    private const SUPPLIERS = [
        ['Distribuidora EcoClean', 'Aseo e higiene', [13], 'APPROVED_FOR_QUOTES'],
        ['Comercial ProSafe Chile', 'Seguridad industrial', [13], 'APPROVED_FOR_QUOTES'],
        ['Suministros Médicos Central', 'Insumos clínicos', [13], 'APPROVED_FOR_QUOTES'],
        ['Papeles y Más SpA', 'Oficina', [5], 'UNDER_REVIEW'],
        ['Transportes Ruta 5', 'Logística', [6, 13], 'APPROVED_FOR_QUOTES'],
        ['Mantenciones Integrales Ltda.', 'Mantención', [13], 'REGISTERED'],
    ];

    public function run(): void
    {
        $tenant = Tenant::query()->where('is_demo', true)->first();
        if (! $tenant) {
            $this->command?->warn('No hay tenant demostrativo: se omiten datos demo.');

            return;
        }
        if (! $tenant->is_demo) {
            throw new RuntimeException('Los datos demo solo pueden cargarse en un tenant demostrativo.');
        }

        app(CurrentTenant::class)->set($tenant);

        $categories = [];
        foreach (self::CATEGORIES as $name) {
            $categories[$name] = Category::query()->firstOrCreate(['name' => $name], ['is_active' => true]);
        }

        foreach (self::CUSTOMERS as [$name, $segment, $contact, $plan, $status]) {
            $org = $this->organization($name, OrganizationKind::MediumCompany, $segment);
            Customer::query()->firstOrCreate(['organization_id' => $org->id], ['status' => CustomerStatus::from($status), 'plan' => $plan, 'source' => 'Prototipo (ficticio)']);
            if (! $org->contacts()->exists()) {
                $org->contacts()->create(['full_name' => $contact, 'email' => $this->email($contact), 'is_primary' => true]);
            }
        }

        foreach (self::SUPPLIERS as [$name, $category, $regions, $status]) {
            $org = $this->organization($name, OrganizationKind::Sme, null);
            $supplier = Supplier::query()->firstOrCreate(['organization_id' => $org->id], ['status' => SupplierStatus::from($status), 'coverage_regions' => $regions]);
            $supplier->categories()->syncWithoutDetaching([$categories[$category]->id]);
        }

        $unit = UnitOfMeasure::query()->where('code', 'EA')->value('id');
        foreach ([['DEMO-ASEO-001', 'Detergente industrial 5 L', 'Aseo e higiene'], ['DEMO-EPP-001', 'Guantes de nitrilo (caja 100)', 'Seguridad industrial'], ['DEMO-OF-001', 'Resma papel carta 500 hojas', 'Oficina']] as [$sku, $name, $category]) {
            Product::query()->firstOrCreate(['sku' => $sku], ['name' => $name.' (ficticio)', 'kind' => 'PRODUCT', 'unit_of_measure_id' => $unit, 'category_id' => $categories[$category]->id, 'is_active' => true]);
        }

        app(CurrentTenant::class)->set(null);
    }

    private function organization(string $name, OrganizationKind $kind, ?string $segment): Organization
    {
        return Organization::query()->firstOrCreate(['legal_name' => $name], ['kind' => $kind, 'segment' => $segment, 'notes' => 'Dato ficticio del prototipo.']);
    }

    private function email(string $fullName): string
    {
        $slug = strtolower((string) preg_replace('/[^a-z]/i', '', iconv('UTF-8', 'ASCII//TRANSLIT', explode(' ', $fullName)[0]) ?: 'contacto'));

        return $slug.'@demo.tiare.test';
    }
}
