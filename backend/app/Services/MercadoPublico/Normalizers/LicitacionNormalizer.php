<?php

namespace App\Services\MercadoPublico\Normalizers;

use App\Domain\PublicProcurement\ChileRegion;
use App\Domain\PublicProcurement\MercadoPublicoV1Catalog as Catalog;
use App\Services\MercadoPublico\DTO\V1Record;
use InvalidArgumentException;

/**
 * Licitación V1 → V1Record con columnas de public_procurement_opportunities.
 * Campos según "Diccionario de Datos – Licitaciones" (ChileCompra, marzo 2026).
 * Un listado (por fecha/estado) solo trae CodigoExterno, Nombre, CodigoEstado y FechaCierre:
 * por eso `isDetail` se decide por la presencia de Comprador/Fechas.
 */
class LicitacionNormalizer
{
    public function __construct(private readonly V1Values $v) {}

    public function normalize(array $raw): V1Record
    {
        $code = $this->v->str($raw, 'CodigoExterno', 64);
        if ($code === null) {
            throw new InvalidArgumentException('Licitación sin CodigoExterno.');
        }

        $isDetail = is_array($raw['Comprador'] ?? null) || is_array($raw['Fechas'] ?? null);
        $statusCode = $this->v->int($raw, 'CodigoEstado');

        $attributes = [
            'title' => $this->v->str($raw, 'Nombre', 500) ?? $code,
            'status_code' => $statusCode !== null ? (string) $statusCode : null,
            'status_source_id' => $statusCode !== null ? (string) $statusCode : null,
            'status_label' => Catalog::tenderStatusLabel($statusCode, $this->v->str($raw, 'Estado', 120)),
            'closes_at' => $this->v->date($raw, 'Fechas.FechaCierre') ?? $this->v->date($raw, 'FechaCierre'),
        ];

        $items = [];
        if ($isDetail) {
            $currency = $this->v->str($raw, 'Moneda', 3);
            $estimated = $this->v->decimal($raw, 'MontoEstimado');
            $regionName = $this->v->str($raw, 'Comprador.RegionUnidad', 120);
            $estimation = $this->v->int($raw, 'Estimacion');

            $attributes += [
                'description' => $this->v->str($raw, 'Descripcion', 20000),
                'procedure_type' => $this->v->str($raw, 'Tipo', 8),
                'published_at' => $this->v->date($raw, 'Fechas.FechaPublicacion'),
                'awarded_at' => $this->v->date($raw, 'Fechas.FechaAdjudicacion'),
                'buyer_code' => $this->v->str($raw, 'Comprador.CodigoOrganismo', 32),
                'buyer_name' => $this->v->str($raw, 'Comprador.NombreOrganismo', 255),
                'buyer_rut' => $this->v->str($raw, 'Comprador.RutUnidad', 16),
                'buyer_unit' => $this->v->str($raw, 'Comprador.NombreUnidad', 255),
                'region_name' => $regionName,
                'region_code' => ChileRegion::codeFromName($regionName),
                'currency' => $currency,
                'budget_type' => $estimation !== null ? (Catalog::ESTIMATION[$estimation] ?? (string) $estimation) : null,
                'estimated_budget' => $estimated,
                // Solo se informa monto en CLP cuando la moneda original es CLP (no se convierte).
                'available_amount_clp' => $currency === 'CLP' ? $estimated : null,
                'delivery_address' => $this->v->str($raw, 'DireccionEntrega', 500),
                'details' => $this->details($raw),
            ];

            foreach ($this->v->list($raw, 'Items.Listado') as $i => $item) {
                $items[] = [
                    'line_number' => $this->v->int($item, 'Correlativo') ?? $i + 1,
                    'product_code' => $this->v->str($item, 'CodigoProducto', 32),
                    'name' => $this->v->str($item, 'NombreProducto', 500),
                    'description' => $this->v->str($item, 'Descripcion', 5000),
                    'quantity' => $this->v->decimal($item, 'Cantidad', 4),
                    'unit_of_measure' => $this->v->str($item, 'UnidadMedida', 32),
                    'category' => $this->v->str($item, 'Categoria', 400),
                    'awarded_supplier_rut' => $this->v->str($item, 'Adjudicacion.RutProveedor', 16),
                    'awarded_supplier_name' => $this->v->str($item, 'Adjudicacion.NombreProveedor', 500),
                    'awarded_quantity' => $this->v->decimal($item, 'Adjudicacion.CantidadAdjudicada', 4),
                    'awarded_unit_price' => $this->v->decimal($item, 'Adjudicacion.MontoUnitario'),
                ];
            }
        }

        $clean = $this->v->scrub($raw);

        return new V1Record($code, $isDetail, $attributes, $items, $clean, $this->v->hash($clean));
    }

    /** Atributos informativos sin índice. Solo valores documentados; null si no vienen. */
    private function details(array $raw): array
    {
        $modality = $this->v->int($raw, 'Modalidad');
        $durationUnit = $this->v->int($raw, 'UnidadTiempoDuracionContrato');
        $awardAct = $this->v->int($raw, 'Adjudicacion.Tipo');

        return [
            'type_label' => Catalog::TENDER_TYPES[$this->v->str($raw, 'Tipo', 8) ?? ''] ?? null,
            'is_public' => match ($this->v->int($raw, 'CodigoTipo')) {
                1 => true, 2 => false, default => null
            },
            'open_call' => $this->v->bool($raw, 'TipoConvocatoria'),
            'stages' => $this->v->int($raw, 'Etapas'),
            'days_to_close' => $this->v->int($raw, 'DiasCierreLicitacion'),
            'requires_comptroller_review' => $this->v->bool($raw, 'TomaRazon'),
            'is_works' => match ($this->v->int($raw, 'Obras')) {
                2 => true, 0 => false, default => null
            },
            'complaints_count' => $this->v->int($raw, 'CantidadReclamos'),
            'amount_visible' => $this->v->bool($raw, 'VisibilidadMonto'),
            'estimated_amount_justification' => $this->v->str($raw, 'JustificacionMontoEstimado', 255),
            'payment_modality' => $modality !== null ? (Catalog::PAYMENT_MODALITY[$modality] ?? (string) $modality) : null,
            'subcontracting_allowed' => $this->v->bool($raw, 'SubContratacion'),
            'contract_duration' => ($d = $this->v->int($raw, 'TiempoDuracionContrato')) !== null
                ? trim($d.' '.(Catalog::TIME_UNIT[$durationUnit ?? 0] ?? $this->v->str($raw, 'TipoDuracionContrato', 60) ?? ''))
                : null,
            'renewable' => $this->v->bool($raw, 'EsRenovable'),
            'auto_extension' => $this->v->bool($raw, 'ExtensionPlazo'),
            'site_visit_address' => $this->v->str($raw, 'DireccionVisita', 500),
            'dates' => array_filter([
                'created_at' => V1Values::iso($this->v->date($raw, 'Fechas.FechaCreacion')),
                'questions_start_at' => V1Values::iso($this->v->date($raw, 'Fechas.FechaInicio')),
                'questions_end_at' => V1Values::iso($this->v->date($raw, 'Fechas.FechaFinal')),
                'answers_published_at' => V1Values::iso($this->v->date($raw, 'Fechas.FechaPubRespuestas')),
                'technical_opening_at' => V1Values::iso($this->v->date($raw, 'Fechas.FechaActoAperturaTecnica')),
                'economic_opening_at' => V1Values::iso($this->v->date($raw, 'Fechas.FechaActoAperturaEconomica')),
                'estimated_award_at' => V1Values::iso($this->v->date($raw, 'Fechas.FechaEstimadaAdjudicacion')),
                'site_visit_at' => V1Values::iso($this->v->date($raw, 'Fechas.FechaVisitaTerreno')),
                'documents_due_at' => V1Values::iso($this->v->date($raw, 'Fechas.FechaEntregaAntecedentes')),
                'estimated_signature_at' => V1Values::iso($this->v->date($raw, 'Fechas.FechaEstimadaFirma')),
            ]),
            'award' => is_array($raw['Adjudicacion'] ?? null) ? [
                'act_type' => $awardAct !== null ? (Catalog::AWARD_ACT[$awardAct] ?? (string) $awardAct) : null,
                'act_number' => $this->v->str($raw, 'Adjudicacion.Numero', 100),
                'act_date' => V1Values::iso($this->v->date($raw, 'Adjudicacion.Fecha')),
                'awarded_suppliers' => $this->v->int($raw, 'Adjudicacion.NumeroOferentes'),
                'record_url' => $this->v->officialUrl($raw, 'Adjudicacion.UrlActa'),
            ] : null,
        ];
    }
}
