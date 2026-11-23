<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Feature\Crm\CrmTestCase;

class InvoicesAndIndicatorsTest extends CrmTestCase
{
    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-18 15:00:00');
        $this->approver = $this->userWithTenantRole('tenant_approver', $this->tenantA);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Recorre el flujo hasta emitir la orden. Alfa 181.475 (5 días), Beta 172.550 (2 días). Estimado neto 160.000. */
    private function orderedRequest(string $expected = '2026-11-25'): array
    {
        $sup = fn ($n) => $this->actingAs($this->adminA)->postJson('/api/v1/suppliers', ['organization' => ['legal_name' => $n, 'kind' => 'SME']])->json('data.public_id');
        [$a, $b] = [$sup('Alfa SpA'), $sup('Beta SpA')];
        $id = $this->actingAs($this->adminA)->postJson('/api/v1/purchase-requests', ['title' => 'Insumos', 'items' => [
            ['description' => 'Detergente', 'quantity' => 10, 'estimated_unit_price' => 12000],
            ['description' => 'Guantes', 'quantity' => 5, 'estimated_unit_price' => 8000],
        ]])->json('data.public_id');
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/submit");
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/start-quoting");
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/quotations", ['supplier_public_id' => $a, 'shipping_cost' => 5000, 'delivery_days' => 5, 'items' => [['line_number' => 1, 'unit_price' => 11000], ['line_number' => 2, 'unit_price' => 7500]]]);
        $q = $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/quotations", ['supplier_public_id' => $b, 'prices_include_tax' => true, 'delivery_days' => 2, 'items' => [['line_number' => 1, 'unit_price' => 13090], ['line_number' => 2, 'unit_price' => 8330]]])
            ->json('data.quotations');
        $beta = collect($q)->firstWhere('supplier.public_id', $b)['public_id'];
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/compare");
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/propose", ['quotation_public_id' => $beta])->assertOk();
        $this->actingAs($this->approver)->postJson("/api/v1/purchase-requests/{$id}/decision", ['decision' => 'APPROVED'])->assertOk();

        return $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/order", [
            'payer_name' => 'Empresa A', 'invoice_holder_name' => 'Empresa A', 'contractual_buyer' => 'Empresa A', 'receiver_name' => 'Bodega', 'expected_delivery_on' => $expected,
        ])->assertCreated()->json('data');
    }

    public function test_invoices_are_registered_compared_with_order_and_payment_informed(): void
    {
        $order = $this->orderedRequest();
        $oid = $order['public_id'];

        $data = $this->actingAs($this->adminA)->postJson("/api/v1/purchase-orders/{$oid}/invoices", [
            'number' => '1001', 'issued_on' => '2026-11-18', 'due_on' => '2026-12-18', 'net_amount' => 145000, 'tax_amount' => 27550,
        ])->assertCreated()->json('data');
        $this->assertSame('172550.00', $data['invoices'][0]['total']);
        $this->assertTrue($data['invoicing']['matches']);

        // Folio duplicado y moneda distinta.
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-orders/{$oid}/invoices", ['number' => '1001', 'issued_on' => '2026-11-18', 'net_amount' => 1])
            ->assertStatus(422)->assertJsonPath('error.details.number.0', 'Ya está registrado ese folio para este proveedor.');
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-orders/{$oid}/invoices", ['number' => '1002', 'issued_on' => '2026-11-18', 'net_amount' => 1, 'currency' => 'USD'])->assertStatus(422);

        // Nota de crédito: la diferencia se muestra, no se bloquea.
        $data = $this->actingAs($this->adminA)->postJson("/api/v1/purchase-orders/{$oid}/invoices", ['number' => '55', 'document_type' => 'CREDIT_NOTE', 'issued_on' => '2026-11-18', 'net_amount' => 10000, 'tax_amount' => 1900])
            ->assertCreated()->json('data');
        $this->assertSame('-11900.00', $data['invoicing']['difference']);
        $this->assertFalse($data['invoicing']['matches']);

        $invoice = collect($data['invoices'])->firstWhere('number', '1001')['public_id'];
        $this->actingAs($this->adminA)->postJson("/api/v1/invoices/{$invoice}/payment", ['paid_on' => '2026-11-18', 'paid_by_label' => 'Empresa A', 'payment_reference' => 'TRF-889'])
            ->assertOk()->assertJsonPath('data.status', 'PAID');
        $this->actingAs($this->adminA)->postJson("/api/v1/invoices/{$invoice}/payment", ['paid_on' => '2026-11-18', 'paid_by_label' => 'X'])->assertStatus(422);

        $this->actingAs($this->adminA)->getJson('/api/v1/invoices?status=PAID')->assertJsonPath('meta.pagination.total', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'invoices.payment_informed']);
    }

    public function test_invoice_permissions_and_isolation(): void
    {
        $order = $this->orderedRequest();
        $reader = $this->userWithTenantRole('tenant_read_only', $this->tenantA);
        $payload = ['number' => '9', 'issued_on' => '2026-11-18', 'net_amount' => 1];

        $this->actingAs($reader)->postJson("/api/v1/purchase-orders/{$order['public_id']}/invoices", $payload)->assertForbidden();
        $this->actingAs($this->adminB)->postJson("/api/v1/purchase-orders/{$order['public_id']}/invoices", $payload)->assertNotFound();
        $this->actingAs($this->adminB)->getJson('/api/v1/invoices')->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_indicators_are_computed_with_methodology_and_sample_size(): void
    {
        $order = $this->orderedRequest('2026-11-25');
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-orders/{$order['public_id']}/receipts", [
            'received_on' => '2026-11-18', 'items' => [['line_number' => 1, 'quantity_received' => 10], ['line_number' => 2, 'quantity_received' => 5, 'quantity_rejected' => 1, 'incident_note' => 'Caja dañada']],
        ])->assertOk();
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-orders/{$order['public_id']}/invoices", ['number' => '1', 'issued_on' => '2026-11-10', 'due_on' => '2026-11-17', 'net_amount' => 145000, 'tax_amount' => 27550]);

        $data = $this->actingAs($this->adminA)->getJson('/api/v1/procurement/indicators?from=2026-11-01&to=2026-11-30')->assertOk()->json('data');
        $m = collect($data['metrics'])->keyBy('key');

        $this->assertSame('172550', $m['spend']['value']);
        $this->assertSame(1, $m['spend']['n']);
        // Estimado neto 160.000 − neto adjudicado 145.000 = 15.000 (9,4 %).
        $this->assertSame('15000', $m['savings_vs_estimate']['value']);
        $this->assertEquals(9.4, $m['savings_vs_estimate']['percent']);
        // Promedio (181.475 + 172.550)/2 = 177.012,5 − 172.550 = 4.462,5 → 4.463
        $this->assertSame('4463', $m['competitive_savings']['value']);
        $this->assertEquals(100, $m['on_time']['value']);
        $this->assertEquals(100, $m['incident_rate']['value']);
        $this->assertEquals(2, $m['quotes_per_request']['value']);
        $this->assertNotEmpty($m['savings_vs_estimate']['assumptions']);
        $this->assertNotEmpty($m['spend']['formula']);
        $this->assertSame(1, $data['invoices']['pending_count']);
        $this->assertSame(1, $data['invoices']['overdue_count']);

        // Período sin datos: valores nulos, no ceros inventados.
        $empty = collect($this->actingAs($this->adminA)->getJson('/api/v1/procurement/indicators?from=2025-01-01&to=2025-01-31')->json('data.metrics'))->keyBy('key');
        $this->assertNull($empty['spend']['value']);
        $this->assertSame(0, $empty['spend']['n']);

        $buyer = $this->userWithTenantRole('tenant_procurement_user', $this->tenantA);
        $this->actingAs($buyer)->getJson('/api/v1/procurement/indicators')->assertForbidden(); // sin reports.read
    }
}
