<?php

namespace Database\Seeders;

use App\Models\UnitOfMeasure;
use Illuminate\Database\Seeder;

/**
 * Unidades de medida de referencia. Incluye los códigos observados en Compra Ágil
 * (EA = unidad, KG = kilogramo); el resto son unidades de uso común. Idempotente.
 */
class UnitsOfMeasureSeeder extends Seeder
{
    public const UNITS = [
        'EA' => 'Unidad',
        'KG' => 'Kilogramo',
        'G' => 'Gramo',
        'L' => 'Litro',
        'ML' => 'Mililitro',
        'M' => 'Metro',
        'M2' => 'Metro cuadrado',
        'M3' => 'Metro cúbico',
        'BOX' => 'Caja',
        'PK' => 'Paquete',
        'PR' => 'Par',
        'H' => 'Hora',
        'DAY' => 'Día',
        'MON' => 'Mes',
        'SVC' => 'Servicio',
    ];

    public function run(): void
    {
        foreach (self::UNITS as $code => $name) {
            UnitOfMeasure::query()->updateOrCreate(['code' => $code], ['name' => $name]);
        }
    }
}
