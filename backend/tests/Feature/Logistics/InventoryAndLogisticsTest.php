<?php

namespace Tests\Feature\Logistics;

use App\Models\InventoryMovement;
use App\Models\ShipmentEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\Feature\Crm\CrmTestCase;

class InventoryAndLogisticsTest extends CrmTestCase
{
    private string $product;

    private string $wh1;

    private string $wh2;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        CarbonImmutable::setTestNow('2026-11-19 15:00:00');
        $this->product = $this->actingAs($this->adminA)->postJson('/api/v1/products', ['name' => 'Detergente 5 L', 'kind' => 'PRODUCT', 'unit_code' => 'EA', 'sku' => 'DET-5'])->assertCreated()->json('data.public_id');
        $this->wh1 = $this->actingAs($this->adminA)->postJson('/api/v1/warehouses', ['code' => 'CENTRAL', 'name' => 'Bodega central', 'region_code' => 13])->assertCreated()->json('data.public_id');
        $this->wh2 = $this->actingAs($this->adminA)->postJson('/api/v1/warehouses', ['code' => 'NORTE', 'name' => 'Bodega norte'])->assertCreated()->json('data.public_id');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function move(array $data, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->adminA)->postJson('/api/v1/inventory/movements', $data + ['product_public_id' => $this->product, 'warehouse_public_id' => $this->wh1]);
    }

    public function test_stock_movements_balances_levels_and_transfer(): void
    {
        $this->move(['type' => 'IN', 'quantity' => 20, 'unit_cost' => 9000, 'lot' => 'L-01'])->assertCreated()->assertJsonPath('data.quantity_on_hand', '20.0000');
        $this->move(['type' => 'OUT', 'quantity' => 25])->assertStatus(422); // sin stock negativo
        $this->move(['type' => 'OUT', 'quantity' => 5])->assertCreated()->assertJsonPath('data.quantity_on_hand', '15.0000');
        $this->move(['type' => 'ADJUST_OUT', 'quantity' => 1])->assertStatus(422)->assertJsonPath('error.details.reason.0', fn ($m) => str_contains($m, 'motivo'));
        $this->move(['type' => 'ADJUST_OUT', 'quantity' => 1, 'reason' => 'Conteo físico'])->assertCreated()->assertJsonPath('data.quantity_on_hand', '14.0000');

        $this->move(['type' => 'TRANSFER', 'quantity' => 4, 'to_warehouse_public_id' => $this->wh2])->assertCreated()->assertJsonPath('data.quantity_on_hand', '10.0000');
        $north = collect($this->actingAs($this->adminA)->getJson("/api/v1/inventory/stock?warehouse_public_id={$this->wh2}")->json('data'))->first();
        $this->assertSame('4.0000', $north['quantity_on_hand']);

        // Mínimo/máximo y sugerencia de reposición.
        $this->actingAs($this->adminA)->patchJson("/api/v1/inventory/stock/{$this->wh1}/{$this->product}", ['min_quantity' => 12, 'max_quantity' => 30, 'location' => 'A-3'])
            ->assertOk()->assertJsonPath('data.low_stock', true)->assertJsonPath('data.reorder_suggestion', '20.0000');
        $this->actingAs($this->adminA)->getJson('/api/v1/inventory/stock?low_only=1')->assertJsonPath('meta.low_stock_count', 1)->assertJsonPath('meta.pagination.total', 1);
        $this->actingAs($this->adminA)->patchJson("/api/v1/inventory/stock/{$this->wh1}/{$this->product}", ['min_quantity' => 10, 'max_quantity' => 5])->assertStatus(422);

        $movs = $this->actingAs($this->adminA)->getJson('/api/v1/inventory/movements')->assertOk()->json('data');
        $this->assertCount(5, $movs); // los intentos rechazados no generan movimientos
        $this->assertSame('TRANSFER_IN', $movs[0]['type']['code']);

        $this->expectException(LogicException::class);
        InventoryMovement::withoutTenantScope()->firstOrFail()->update(['quantity' => 1]);
    }

    public function test_inventory_permissions_and_isolation(): void
    {
        $reader = $this->userWithTenantRole('tenant_read_only', $this->tenantA);
        $this->actingAs($reader)->getJson('/api/v1/inventory/stock')->assertOk();
        $this->move(['type' => 'IN', 'quantity' => 1], $reader)->assertForbidden();
        $this->actingAs($this->adminB)->postJson('/api/v1/inventory/movements', ['type' => 'IN', 'quantity' => 1, 'product_public_id' => $this->product, 'warehouse_public_id' => $this->wh1])->assertStatus(422);
        $this->actingAs($this->adminB)->getJson('/api/v1/warehouses')->assertJsonCount(0, 'data');
        $this->actingAs($this->adminA)->postJson('/api/v1/warehouses', ['code' => 'CENTRAL', 'name' => 'Duplicada'])->assertStatus(422);
    }

    public function test_shipment_lifecycle_with_incident_and_delivery_evidence(): void
    {
        $carrier = $this->actingAs($this->adminA)->postJson('/api/v1/carriers', [
            'name' => 'Transportes Ficticios', 'service_types' => ['STANDARD', 'EXPRESS'], 'coverage_regions' => [13, 5],
            'tracking_url_template' => 'https://transportes.example.test/seguimiento/{code}',
        ])->assertCreated()->json('data.public_id');
        $this->actingAs($this->adminA)->postJson('/api/v1/carriers', ['name' => 'X', 'tracking_url_template' => 'http://malo/{code}'])->assertStatus(422);

        $s = $this->actingAs($this->adminA)->postJson('/api/v1/shipments', [
            'carrier_public_id' => $carrier, 'service_type' => 'EXPRESS', 'origin' => 'Bodega central', 'destination' => 'Av. Ficticia 123, Valparaíso',
            'destination_region' => 5, 'tracking_code' => 'ABC 123', 'planned_delivery_on' => '2026-11-18', 'estimated_cost' => 15000,
        ])->assertCreated()->assertJsonPath('data.number', 'DSP-2026-0001')->assertJsonPath('data.status.code', 'PLANNED')
            ->assertJsonPath('data.late', true)
            ->assertJsonPath('data.tracking_url', 'https://transportes.example.test/seguimiento/ABC%20123')->json('data');
        $id = $s['public_id'];

        $this->actingAs($this->adminA)->postJson("/api/v1/shipments/{$id}/status", ['status' => 'RETURNED'])->assertStatus(422); // transición no permitida
        $this->actingAs($this->adminA)->postJson("/api/v1/shipments/{$id}/status", ['status' => 'IN_TRANSIT', 'location' => 'Santiago'])->assertOk()->assertJsonPath('data.status.code', 'IN_TRANSIT');
        $this->actingAs($this->adminA)->postJson("/api/v1/shipments/{$id}/status", ['status' => 'FAILED'])->assertStatus(422); // falta motivo
        $this->actingAs($this->adminA)->postJson("/api/v1/shipments/{$id}/status", ['status' => 'FAILED', 'description' => 'Destinatario ausente'])->assertOk();

        $inc = $this->actingAs($this->adminA)->postJson("/api/v1/shipments/{$id}/incidents", ['type' => 'DELAY', 'description' => 'Reprogramado para mañana'])
            ->assertCreated()->assertJsonPath('data.open_incidents', 1)->json('data.incidents.0.public_id');
        $this->actingAs($this->adminA)->postJson("/api/v1/shipments/{$id}/incidents/{$inc}/resolve", ['resolution' => 'Entregado en segundo intento'])->assertOk()->assertJsonPath('data.open_incidents', 0);

        $this->actingAs($this->adminA)->post("/api/v1/shipments/{$id}/deliver", [
            'received_by_name' => 'Persona Ficticia', 'evidence' => UploadedFile::fake()->create('firma.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status.code', 'DELIVERED')->assertJsonPath('data.has_evidence', true)->assertJsonPath('data.late', false);

        $this->actingAs($this->adminA)->get("/api/v1/shipments/{$id}/evidence")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->adminA)->postJson("/api/v1/shipments/{$id}/status", ['status' => 'IN_TRANSIT'])->assertStatus(422); // cerrado
        $this->actingAs($this->adminA)->patchJson("/api/v1/shipments/{$id}", ['actual_cost' => 16500])->assertOk()->assertJsonPath('data.actual_cost', '16500.00');
        $this->actingAs($this->adminA)->patchJson("/api/v1/shipments/{$id}", ['destination' => 'Otra'])->assertStatus(422);

        $this->assertGreaterThanOrEqual(6, ShipmentEvent::query()->count());
        $this->expectException(LogicException::class);
        ShipmentEvent::query()->firstOrFail()->delete();
    }

    public function test_goods_receipt_can_enter_stock_for_catalog_items(): void
    {
        $approver = $this->userWithTenantRole('tenant_approver', $this->tenantA);
        $sup = $this->actingAs($this->adminA)->postJson('/api/v1/suppliers', ['organization' => ['legal_name' => 'Proveedor Uno SpA', 'kind' => 'SME']])->json('data.public_id');
        $id = $this->actingAs($this->adminA)->postJson('/api/v1/purchase-requests', ['title' => 'Reposición', 'items' => [
            ['product_public_id' => $this->product, 'quantity' => 10],
            ['description' => 'Servicio de flete', 'quantity' => 1],
        ]])->assertCreated()->json('data.public_id');
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/submit");
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/start-quoting");
        $q = $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/quotations", ['supplier_public_id' => $sup, 'items' => [['line_number' => 1, 'unit_price' => 9500], ['line_number' => 2, 'unit_price' => 20000]]])
            ->json('data.quotations.0.public_id');
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/propose", ['quotation_public_id' => $q]);
        $this->actingAs($approver)->postJson("/api/v1/purchase-requests/{$id}/decision", ['decision' => 'APPROVED'])->assertOk();
        $order = $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/order", ['payer_name' => 'Empresa A', 'invoice_holder_name' => 'AA', 'contractual_buyer' => 'AA', 'receiver_name' => 'Bodega'])->json('data.public_id');

        // Un usuario de compras recibe, pero no puede ingresar a bodega sin permiso de inventario.
        $buyer = $this->userWithTenantRole('tenant_procurement_user', $this->tenantA);
        $payload = ['received_on' => '2026-11-19', 'warehouse_public_id' => $this->wh1, 'items' => [['line_number' => 1, 'quantity_received' => 8], ['line_number' => 2, 'quantity_received' => 1]]];
        $this->actingAs($buyer)->postJson("/api/v1/purchase-orders/{$order}/receipts", $payload)->assertForbidden();

        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-orders/{$order}/receipts", $payload)->assertOk();
        $stock = collect($this->actingAs($this->adminA)->getJson("/api/v1/inventory/stock?warehouse_public_id={$this->wh1}")->json('data'))->first();
        $this->assertSame('8.0000', $stock['quantity_on_hand']); // el flete (sin producto) no entra a bodega
        $mov = $this->actingAs($this->adminA)->getJson('/api/v1/inventory/movements')->json('data.0');
        $this->assertSame('goods_receipt', $mov['reference_type']);
        $this->assertSame('9500.00', $mov['unit_cost']);
    }

    public function test_shipment_permissions_and_isolation(): void
    {
        $id = $this->actingAs($this->adminA)->postJson('/api/v1/shipments', ['origin' => 'Bodega central', 'destination' => 'Cliente final'])->assertCreated()->json('data.public_id');
        $reader = $this->userWithTenantRole('tenant_read_only', $this->tenantA);
        $this->actingAs($reader)->getJson("/api/v1/shipments/{$id}")->assertOk()->assertJsonPath('data.actions.deliver', false);
        $this->actingAs($reader)->postJson("/api/v1/shipments/{$id}/status", ['status' => 'IN_TRANSIT'])->assertForbidden();
        $this->actingAs($this->adminB)->getJson("/api/v1/shipments/{$id}")->assertNotFound();
    }
}
