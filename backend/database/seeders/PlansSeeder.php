<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Catálogo de planes (TD-024). Idempotente.
 * Contenido PROPUESTO por desarrollo a partir de los nombres de plan usados en el CRM;
 * los módulos y límites definitivos los define el área comercial (PEND-T11).
 */
class PlansSeeder extends Seeder
{
    /** key => [nombre, descripción, módulos, máximo de usuarios] */
    public const PLANS = [
        'start' => ['Start', 'Compras privadas básicas: solicitudes, cotizaciones, proveedores y catálogo.', ['procurement', 'suppliers', 'catalog', 'crm', 'reports'], 5],
        'growth' => ['Growth', 'Start más inventario, logística y agentes IA.', ['procurement', 'suppliers', 'catalog', 'crm', 'reports', 'inventory', 'logistics', 'agents'], 15],
        'managed' => ['Managed', 'Departamento de compras externalizado: todos los módulos con operación TIARE.', ['public_procurement', 'procurement', 'suppliers', 'catalog', 'crm', 'reports', 'inventory', 'logistics', 'agents'], 25],
        'public' => ['Public', 'Inteligencia de oportunidades en Mercado Público.', ['public_procurement', 'crm', 'reports', 'agents'], 5],
        'saas' => ['SaaS', 'Plataforma completa en modalidad autoservicio.', ['public_procurement', 'procurement', 'suppliers', 'catalog', 'crm', 'reports', 'inventory', 'logistics', 'agents'], null],
    ];

    public function run(): void
    {
        foreach (self::PLANS as $key => [$name, $description, $modules, $maxUsers]) {
            Plan::query()->updateOrCreate(['key' => $key], [
                'name' => $name,
                'description' => $description,
                'modules' => $modules,
                'max_users' => $maxUsers,
                'is_active' => true,
            ]);
        }
    }
}
