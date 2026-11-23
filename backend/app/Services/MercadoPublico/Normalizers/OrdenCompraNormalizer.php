<?php

namespace App\Services\MercadoPublico\Normalizers;

use App\Domain\PublicProcurement\MercadoPublicoV1Catalog as Catalog;
use App\Services\MercadoPublico\DTO\V1Record;
use InvalidArgumentException;

/**
 * Orden de compra V1 → V1Record con columnas de public_purchase_orders.
 * Campos según "Diccionario de Datos – Órdenes de Compra" (ChileCompra, marzo 2026).
 */
class OrdenCompraNormalizer
{
    public function __construct(private readonly V1Values $v) {}

    public function normalize(array $raw): V1Record
    {
        $code = $this->v->str($raw, 'Codigo', 64);
        if ($code === null) {
            throw new InvalidArgumentException('Orden de compra sin Codigo.');
        }

        $isDetail = is_array($raw['Comprador'] ?? null) || is_array($raw['Proveedor'] ?? null) || is_array($raw['Fechas'] ?? null);
        $statusCode = $this->v->int($raw, 'CodigoEstado');

        $attributes = [
            'title' => $this->v->str($raw, 'Nombre', 500),
            'status_code' => $statusCode,
            'status_label' => Catalog::orderStatusLabel($statusCode, $this->v->str($raw, 'Estado', 120)),
        ];
        // El listado puede traer el total; se guarda si viene.
        if (($total = $this->v->decimal($raw, 'Total')) !== null) {
            $attributes['total'] = $total;
        }

        $items = [];
        if ($isDetail) {
            $dispatch = $this->v->int($raw, 'TipoDespacho');
            $payment = $this->v->int($raw, 'FormaPago');

            $attributes += [
                'description' => $this->v->str($raw, 'Descripcion', 20000),
                'supplier_status_label' => $this->v->str($raw, 'EstadoProveedor', 120),
                'tender_code' => $this->v->str($raw, 'CodigoLicitacion', 64),
                'type_code' => $this->v->str($raw, 'CodigoTipo', 16),
                'type_label' => $this->v->str($raw, 'Tipo', 80),
                'currency' => $this->v->str($raw, 'TipoMoneda', 3),
                'buyer_code' => $this->v->str($raw, 'Comprador.CodigoOrganismo', 32),
                'buyer_name' => $this->v->str($raw, 'Comprador.NombreOrganismo', 255),
                'buyer_unit_rut' => $this->v->str($raw, 'Comprador.RutUnidad', 16),
                'buyer_unit' => $this->v->str($raw, 'Comprador.NombreUnidad', 255),
                'buyer_region' => $this->v->str($raw, 'Comprador.RegionUnidad', 120),
                'supplier_code' => $this->v->str($raw, 'Proveedor.Codigo', 32),
                'supplier_name' => $this->v->str($raw, 'Proveedor.Nombre', 255),
                'supplier_rut' => $this->v->str($raw, 'Proveedor.RutSucursal', 16),
                'supplier_region' => $this->v->str($raw, 'Proveedor.Region', 120),
                'net_total' => $this->v->decimal($raw, 'TotalNeto'),
                'tax_total' => $this->v->decimal($raw, 'Impuestos'),
                'tax_rate' => $this->v->decimal($raw, 'PorcentajeIva'),
                'issued_at' => $this->v->date($raw, 'Fechas.FechaCreacion'),
                'sent_at' => $this->v->date($raw, 'Fechas.FechaEnvio'),
                'accepted_at' => $this->v->date($raw, 'Fechas.FechaAceptacion'),
                'cancelled_at' => $this->v->date($raw, 'Fechas.FechaCancelacion'),
                'source_updated_at' => $this->v->date($raw, 'Fechas.FechaUltimaModificacion'),
                'details' => [
                    'discounts' => $this->v->decimal($raw, 'Descuentos'),
                    'charges' => $this->v->decimal($raw, 'Cargos'),
                    'funding' => $this->v->str($raw, 'Financiamiento', 255),
                    'country' => $this->v->str($raw, 'Pais', 80),
                    'dispatch_type' => $dispatch !== null ? (Catalog::DISPATCH_TYPE[$dispatch] ?? (string) $dispatch) : null,
                    'payment_terms' => $payment !== null ? (Catalog::ORDER_PAYMENT[$payment] ?? (string) $payment) : null,
                    'supplier_rating_avg' => $this->v->decimal($raw, 'PromedioCalificacion'),
                    'supplier_rating_count' => $this->v->int($raw, 'CantidadEvaluacion'),
                    'buyer_activity' => $this->v->str($raw, 'Comprador.Actividad', 255),
                    'supplier_activity' => $this->v->str($raw, 'Proveedor.Actividad', 255),
                ],
            ];

            foreach ($this->v->list($raw, 'Items.Listado') as $i => $item) {
                $items[] = [
                    'line_number' => $this->v->int($item, 'Correlativo') ?? $i + 1,
                    'product_code' => $this->v->str($item, 'CodigoProducto', 32),
                    'category' => $this->v->str($item, 'Categoria', 400),
                    'buyer_spec' => $this->v->str($item, 'EspecificacionComprador', 5000),
                    'supplier_spec' => $this->v->str($item, 'EspecificacionProveedor', 5000),
                    'quantity' => $this->v->decimal($item, 'Cantidad', 4),
                    'unit' => $this->v->str($item, 'Unidad', 32),
                    'currency' => $this->v->str($item, 'Moneda', 3),
                    'unit_net_price' => $this->v->decimal($item, 'PrecioNeto'),
                    'total' => $this->v->decimal($item, 'Total'),
                ];
            }
            $attributes['items'] = $items;
        }

        $clean = $this->v->scrub($raw);

        return new V1Record($code, $isDetail, $attributes, $items, $clean, $this->v->hash($clean));
    }
}
