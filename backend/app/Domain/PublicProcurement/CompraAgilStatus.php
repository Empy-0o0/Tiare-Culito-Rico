<?php

namespace App\Domain\PublicProcurement;

/**
 * Estados de Compra Ágil V2 (guía oficial v3.0 §5.1, Grupo 3). Valores originales de la API.
 * `oc_emitida` está en el modelo pero la guía indica que no se usa en la práctica:
 * la emisión de OC se determina por id_orden_compra != null.
 */
enum CompraAgilStatus: string
{
    case Publicada = 'publicada';
    case Cerrada = 'cerrada';
    case Desierta = 'desierta';
    case Cancelada = 'cancelada';
    case ProveedorSeleccionado = 'proveedor_seleccionado';
    case OcEmitida = 'oc_emitida';

    public function label(): string
    {
        return match ($this) {
            self::Publicada => 'Publicada',
            self::Cerrada => 'Cerrada',
            self::Desierta => 'Desierta',
            self::Cancelada => 'Cancelada',
            self::ProveedorSeleccionado => 'Proveedor seleccionado',
            self::OcEmitida => 'OC emitida',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
