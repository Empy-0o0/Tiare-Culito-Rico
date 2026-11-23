<?php

namespace App\Domain\PublicProcurement;

/**
 * Catálogos de la API legacy V1. Fuente: chilecompra.cl/api (revisada 17-11-2026) y diccionarios
 * "Documentacion-API-Mercado-Publico-Licitaciones.pdf" / "-oc.pdf" (marzo 2026).
 * Se guardan siempre código y glosa originales; estas tablas solo complementan glosas faltantes.
 */
final class MercadoPublicoV1Catalog
{
    /** CodigoEstado de licitación → glosa (verbatim chilecompra.cl/api). */
    public const TENDER_STATUS = [
        5 => 'Publicada',
        6 => 'Cerrada',
        7 => 'Desierta',
        8 => 'Adjudicada',
        // Observados en respuestas reales del 17-11-2026 (la API devolvió Estado "Revocada"/"Suspendida"
        // para 15/16). El diccionario oficial informa 18/19: se conservan ambos.
        15 => 'Revocada',
        16 => 'Suspendida',
        18 => 'Revocada',
        19 => 'Suspendida',
    ];

    /** Valores del parámetro `estado` de licitaciones.json (verbatim, en minúsculas como los ejemplos). */
    public const TENDER_STATUS_FILTERS = [
        'activas' => 'Activas (publicadas a la fecha)',
        'publicada' => 'Publicada',
        'cerrada' => 'Cerrada',
        'desierta' => 'Desierta',
        'adjudicada' => 'Adjudicada',
        'revocada' => 'Revocada',
        'suspendida' => 'Suspendida',
        'todos' => 'Todos los estados',
    ];

    /** Tipo de licitación (diccionario §3.1). */
    public const TENDER_TYPES = [
        'L1' => 'Licitación pública menor a 100 UTM',
        'LE' => 'Licitación pública 100 a 1.000 UTM',
        'LP' => 'Licitación pública 1.000 a 2.000 UTM',
        'LQ' => 'Licitación pública 2.000 a 5.000 UTM',
        'LR' => 'Licitación pública igual o superior a 5.000 UTM',
        'LS' => 'Licitación pública de servicios personales especializados',
        'E2' => 'Licitación privada menor a 100 UTM',
        'CO' => 'Licitación privada 100 a 1.000 UTM',
        'B2' => 'Licitación privada 1.000 a 2.000 UTM',
        'H2' => 'Licitación privada 2.000 a 5.000 UTM',
        'I2' => 'Licitación privada mayor a 5.000 UTM',
    ];

    /** Estimacion (diccionario §3.3). */
    public const ESTIMATION = [1 => 'Presupuesto disponible', 2 => 'Precio referencial', 3 => 'Monto no es posible de estimar'];

    /** Modalidad de pago (diccionario §3.4). */
    public const PAYMENT_MODALITY = [
        1 => 'Pago a 30 días', 2 => 'Pago a 30, 60 y 90 días', 3 => 'Pago al día', 4 => 'Pago anual', 5 => 'Pago bimensual',
        6 => 'Pago contra entrega conforme', 7 => 'Pagos mensuales', 8 => 'Pago por estado de avance', 9 => 'Pago trimestral', 10 => 'Pago a 60 días',
    ];

    /** Unidad de tiempo (diccionario §3.5/3.6). */
    public const TIME_UNIT = [1 => 'horas', 2 => 'días', 3 => 'semanas', 4 => 'meses', 5 => 'años'];

    /** Tipo de acto administrativo de adjudicación (diccionario §3.7). */
    public const AWARD_ACT = [1 => 'Autorización', 2 => 'Resolución', 3 => 'Acuerdo', 4 => 'Decreto', 5 => 'Otros'];

    /** CodigoEstado de orden de compra (CLAUDE.md §6.4, documentación oficial). */
    public const ORDER_STATUS = [
        4 => 'Enviada a proveedor',
        5 => 'En proceso',
        6 => 'Aceptada',
        9 => 'Cancelada',
        12 => 'Recepción conforme',
        13 => 'Pendiente de recepcionar',
        14 => 'Recepcionada parcialmente',
        15 => 'Recepción conforme incompleta',
    ];

    /**
     * Valores del parámetro `estado` de ordenesdecompra.json según chilecompra.cl/api.
     * Se conservan las grafías publicadas (incluidas las erratas). PENDIENTE validar con respuesta real.
     */
    public const ORDER_STATUS_FILTERS = [
        'todos' => 'Todos los estados',
        'enviadaproveedor' => 'Enviada a proveedor',
        'aceptada' => 'Aceptada',
        'cancelada' => 'Cancelada',
        'recepcionconforme' => 'Recepción conforme',
        'pendienterecepcion' => 'Pendiente de recepcionar',
        // Validado con la API real el 17-11-2026 (PEND-T11): los valores anteriores funcionan; las grafías
        // publicadas para "recepcionada parcialmente" y "recepción conforme incompleta"
        // (recepcionaceptadacialmente, recepecionconformeincompleta) responden "Los parámetros no son válidos".
        // Se quitan hasta conocer la grafía correcta.
    ];

    /** Tipo de despacho de OC (diccionario OC §3.3). */
    public const DISPATCH_TYPE = [
        7 => 'Despachar a dirección de envío', 9 => 'Despachar según programa adjuntado', 12 => 'Otra forma de despacho',
        14 => 'Retiramos de su bodega', 20 => 'Despacho por courier o encomienda aérea', 21 => 'Despacho por courier o encomienda terrestre', 22 => 'A convenir',
    ];

    /** Forma de pago de OC (diccionario OC §3.4; solo las filas legibles del PDF). */
    public const ORDER_PAYMENT = [
        1 => '15 días contra recepción de factura', 2 => '30 días contra recepción de factura', 39 => 'Otra forma de pago',
        46 => '50 días contra recepción de factura', 47 => '60 días contra recepción de factura',
    ];

    public static function tenderStatusLabel(?int $code, ?string $fallback = null): ?string
    {
        return $fallback ?: ($code !== null ? (self::TENDER_STATUS[$code] ?? null) : null);
    }

    public static function orderStatusLabel(?int $code, ?string $fallback = null): ?string
    {
        return $fallback ?: ($code !== null ? (self::ORDER_STATUS[$code] ?? null) : null);
    }

    /** @param array<int|string,string> $map */
    public static function options(array $map): array
    {
        return collect($map)->map(fn ($label, $code) => ['code' => (string) $code, 'label' => $label])->values()->all();
    }
}
