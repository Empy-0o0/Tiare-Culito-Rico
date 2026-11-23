<?php

namespace App\Services\MercadoPublico\Normalizers;

use App\Domain\PublicProcurement\ChileRegion;
use App\Services\MercadoPublico\DTO\CompraAgilDTO;
use App\Services\MercadoPublico\DTO\CompraAgilItemDTO;
use App\Services\MercadoPublico\Exceptions\MercadoPublicoException;
use App\Services\MercadoPublico\MercadoPublicoConfig;
use App\Support\SourceDateParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Convierte payloads de Compra Ágil V2 (listado o detalle) en CompraAgilDTO.
 * Todos los campos se tratan como opcionales: la API real difiere de la guía (TD-004).
 */
class CompraAgilNormalizer
{
    public function __construct(private readonly MercadoPublicoConfig $config) {}

    /** @param array<string, mixed> $raw */
    public function normalize(array $raw, bool $isDetail): CompraAgilDTO
    {
        $code = $this->str($raw, 'codigo', 64);
        if ($code === null) {
            throw new MercadoPublicoException(MercadoPublicoException::INVALID_RESPONSE, 'Registro de Compra Ágil sin código.');
        }

        // Listado usa montos.*; detalle usa presupuesto.*
        $money = is_array($raw['presupuesto'] ?? null) ? $raw['presupuesto'] : (is_array($raw['montos'] ?? null) ? $raw['montos'] : []);

        // id_orden_compra: raíz (respuesta real) u orden_compra.* (guía).
        $poId = Arr::get($raw, 'id_orden_compra', Arr::get($raw, 'orden_compra.id_orden_compra'));
        $poKnown = array_key_exists('id_orden_compra', $raw) || is_array($raw['orden_compra'] ?? null);

        $region = $this->int($raw, 'institucion.region');
        if ($region !== null && ! ChileRegion::isValid($region)) {
            $region = null;
        }

        return new CompraAgilDTO(
            code: $code,
            title: $this->str($raw, 'nombre', 500) ?? $code,
            isDetail: $isDetail,
            description: $this->str($raw, 'descripcion', 20000),
            statusCode: $this->str($raw, 'estado.codigo', 40),
            statusLabel: $this->str($raw, 'estado.glosa', 120),
            statusSourceId: $this->str($raw, 'estado.id_estado', 16),
            callNumber: $this->int($raw, 'convocatoria.estado_convocatoria'),
            publishedAt: $this->date($raw, 'fechas.fecha_publicacion'),
            closesAt: $this->date($raw, 'fechas.fecha_cierre'),
            firstCallClosesAt: $this->date($raw, 'convocatoria.fecha_cierre_primer_llamado') ?? $this->date($raw, 'fechas.fecha_cierre_primer_llamado'),
            secondCallClosesAt: $this->date($raw, 'convocatoria.fecha_cierre_segundo_llamado') ?? $this->date($raw, 'fechas.fecha_cierre_segundo_llamado'),
            cancelledAt: $this->date($raw, 'fechas.fecha_cancelacion'),
            sourceUpdatedAt: $this->date($raw, 'fechas.fecha_ultimo_cambio'),
            buyerName: $this->str($raw, 'institucion.organismo_comprador', 255),
            buyerRut: $this->str($raw, 'institucion.rut', 16),
            buyerUnit: $this->str($raw, 'institucion.unidad_compra', 255),
            regionCode: $region,
            regionName: $this->str($raw, 'institucion.nombre_region', 120),
            currency: $this->currency($money['moneda'] ?? null),
            budgetType: $this->str($money, 'tipo_presupuesto', 32),
            estimatedBudget: $this->decimal($money['presupuesto_estimado'] ?? null),
            availableAmount: $this->decimal($money['monto_disponible'] ?? null),
            availableAmountClp: $this->decimal($money['monto_disponible_clp'] ?? null),
            exchangeRate: $this->decimal($money['valor_cambio_moneda'] ?? null, 6),
            deliveryAddress: $this->str($raw, 'entrega.direccion_entrega', 500),
            deliveryDays: $this->int($raw, 'entrega.plazo_entrega_dias'),
            offersCount: $this->int($raw, 'resumen.total_ofertas_recibidas'),
            hasPurchaseOrder: $poKnown ? $poId !== null : null,
            purchaseOrderSourceId: $poId !== null && is_scalar($poId) ? (string) $poId : null,
            purchaseOrderCode: $this->str($raw, 'orden_compra.codigo_orden_compra', 64),
            cancellationReason: $this->str($raw, 'motivos.motivo_cancelacion', 1000),
            desertedReason: $this->str($raw, 'motivos.motivo_desierta', 1000),
            selectionReason: $this->str($raw, 'motivos.motivo_seleccion', 1000),
            items: $this->items($raw['productos_solicitados'] ?? null),
            documents: $this->documents($raw['documentos'] ?? null),
            raw: $raw,
            payloadHash: self::hash($raw),
        );
    }

    /** Hash estable (claves ordenadas recursivamente). */
    public static function hash(array $payload): string
    {
        $sort = function (array $a) use (&$sort): array {
            if (! array_is_list($a)) {
                ksort($a);
            }

            return array_map(fn ($v) => is_array($v) ? $sort($v) : $v, $a);
        };

        return hash('sha256', json_encode($sort($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @return list<CompraAgilItemDTO> */
    private function items(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }
        $items = [];
        $line = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $items[] = new CompraAgilItemDTO(
                lineNumber: ++$line,
                productCode: $this->str($row, 'codigo_producto', 32),
                name: $this->str($row, 'nombre', 500),
                description: $this->str($row, 'descripcion', 5000),
                quantity: $this->decimal($row['cantidad'] ?? null, 4),
                unitOfMeasure: $this->str($row, 'unidad_medida', 32),
            );
        }

        return $items;
    }

    /** @return list<array{external_id:string, name:?string}> */
    private function documents(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }
        $docs = [];
        foreach ($rows as $row) {
            $id = is_array($row) ? $this->str($row, 'id', 64) : null;
            if ($id !== null) {
                $docs[$id] = ['external_id' => $id, 'name' => $this->str($row, 'nombre', 500)];
            }
        }

        return array_values($docs);
    }

    private function str(array $data, string $path, int $max): ?string
    {
        $value = Arr::get($data, $path);
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $max - 3);
    }

    private function int(array $data, string $path): ?int
    {
        $value = Arr::get($data, $path);

        return is_numeric($value) ? (int) $value : null;
    }

    private function decimal(mixed $value, int $scale = 2): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value) && is_finite($value)) {
            return rtrim(rtrim(number_format($value, $scale, '.', ''), '0'), '.');
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return trim($value);
        }

        return null;
    }

    private function currency(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z]{3}$/', trim($value)) ? strtoupper(trim($value)) : null;
    }

    private function date(array $data, string $path): ?CarbonImmutable
    {
        return SourceDateParser::parse(Arr::get($data, $path), $this->config->businessTimezone(), $this->config->zIsLocal());
    }
}
