<?php

namespace Tests\Feature\MercadoPublico;

use App\Services\MercadoPublico\Normalizers\CompraAgilNormalizer;
use Tests\TestCase;

class CompraAgilNormalizerTest extends TestCase
{
    private function normalizer(): CompraAgilNormalizer
    {
        return app(CompraAgilNormalizer::class);
    }

    public function test_list_item_is_normalized(): void
    {
        $raw = $this->fixture('compra_agil_list_page1')['payload']['items'][0];
        $dto = $this->normalizer()->normalize($raw, isDetail: false);

        $this->assertSame('9000001-11-COT26', $dto->code);
        $this->assertSame('publicada', $dto->statusCode);
        $this->assertSame('Publicada', $dto->statusLabel);
        $this->assertSame(1, $dto->callNumber);
        $this->assertSame('2026-11-14T00:59:00+00:00', $dto->publishedAt?->toIso8601String());
        $this->assertSame('2026-11-16T14:00:00+00:00', $dto->closesAt?->toIso8601String());
        $this->assertSame('2026-11-16T14:00:00+00:00', $dto->firstCallClosesAt?->toIso8601String());
        $this->assertSame('ORGANISMO PUBLICO FICTICIO UNO', $dto->buyerName);
        $this->assertSame('61.000.001-1', $dto->buyerRut);
        $this->assertSame(13, $dto->regionCode);
        $this->assertSame('CLP', $dto->currency);
        $this->assertSame('1599000', $dto->availableAmountClp);
        $this->assertSame(0, $dto->offersCount);
        $this->assertNull($dto->hasPurchaseOrder, 'El listado no informa OC: debe quedar desconocido');
        $this->assertSame([['external_id' => '1000001', 'name' => 'TDR_FIXTURE.pdf']], $dto->documents);
        $this->assertSame(64, strlen($dto->payloadHash));
    }

    public function test_detail_uses_root_id_orden_compra_as_purchase_order_indicator(): void
    {
        $dto = $this->normalizer()->normalize($this->fixture('compra_agil_detail')['payload'], isDetail: true);

        $this->assertTrue($dto->hasPurchaseOrder);
        $this->assertSame('77777777', $dto->purchaseOrderSourceId);
        $this->assertNull($dto->purchaseOrderCode); // la API suele devolver null aunque exista OC
        $this->assertSame('proveedor_seleccionado', $dto->statusCode);
        $this->assertSame('Disponible', $dto->budgetType);
        $this->assertSame('850000.5', $dto->availableAmountClp);
        $this->assertSame('DIRECCION FICTICIA 123', $dto->deliveryAddress);
        $this->assertSame(5, $dto->deliveryDays);
        $this->assertCount(2, $dto->items);
        $this->assertSame('47131800', $dto->items[0]->productCode);
        $this->assertSame('2.5', $dto->items[1]->quantity);
        $this->assertCount(2, $dto->documents);
    }

    public function test_documented_nested_orden_compra_is_also_supported(): void
    {
        $raw = $this->fixture('compra_agil_detail')['payload'];
        unset($raw['id_orden_compra']);
        $raw['orden_compra'] = ['id_orden_compra' => null, 'codigo_orden_compra' => null];

        $dto = $this->normalizer()->normalize($raw, isDetail: true);
        $this->assertFalse($dto->hasPurchaseOrder);

        $raw['orden_compra']['id_orden_compra'] = 555;
        $this->assertTrue($this->normalizer()->normalize($raw, isDetail: true)->hasPurchaseOrder);
    }

    public function test_oc_emitida_status_alone_does_not_imply_purchase_order(): void
    {
        $raw = $this->fixture('compra_agil_detail')['payload'];
        $raw['estado'] = ['id_estado' => 6, 'codigo' => 'oc_emitida', 'glosa' => 'OC Emitida'];
        $raw['id_orden_compra'] = null;

        $this->assertFalse($this->normalizer()->normalize($raw, isDetail: true)->hasPurchaseOrder);
    }

    public function test_missing_optional_fields_and_invalid_region_do_not_break(): void
    {
        $dto = $this->normalizer()->normalize(['codigo' => '1-2-COT26', 'institucion' => ['region' => 99], 'documentos' => null], isDetail: false);

        $this->assertSame('1-2-COT26', $dto->title);
        $this->assertNull($dto->regionCode);
        $this->assertNull($dto->closesAt);
        $this->assertSame([], $dto->items);
        $this->assertSame([], $dto->documents);
    }

    public function test_hash_is_independent_of_key_order(): void
    {
        $this->assertSame(
            CompraAgilNormalizer::hash(['a' => 1, 'b' => ['y' => 2, 'x' => 1]]),
            CompraAgilNormalizer::hash(['b' => ['x' => 1, 'y' => 2], 'a' => 1]),
        );
    }
}
