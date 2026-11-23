<?php

namespace Tests\Feature\Procurement;

use App\Models\AuditLog;
use App\Models\PurchaseApproval;
use App\Models\QuotationComparison;
use App\Models\User;
use Carbon\CarbonImmutable;
use LogicException;
use Tests\Feature\Crm\CrmTestCase;

class PrivateProcurementFlowTest extends CrmTestCase
{
    private User $buyer;      // usuario de compras (crea la solicitud)

    private User $approver;   // aprobador con límite

    private string $supA;

    private string $supB;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-18 15:00:00');
        $this->buyer = $this->userWithTenantRole('tenant_procurement_user', $this->tenantA);
        $this->approver = $this->userWithTenantRole('tenant_approver', $this->tenantA);
        $this->tenantA->users()->updateExistingPivot($this->approver->id, ['approval_limit_clp' => 5_000_000]);

        $this->supA = $this->supplier('Proveedor Alfa SpA');
        $this->supB = $this->supplier('Proveedor Beta SpA');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function supplier(string $name): string
    {
        return $this->actingAs($this->adminA)->postJson('/api/v1/suppliers', ['organization' => ['legal_name' => $name, 'kind' => 'SME']])->assertCreated()->json('data.public_id');
    }

    private function createRequest(): array
    {
        return $this->actingAs($this->buyer)->postJson('/api/v1/purchase-requests', [
            'title' => 'Insumos de aseo para bodega',
            'priority' => 'HIGH',
            'needed_by' => '2026-11-30',
            'items' => [
                ['description' => 'Detergente industrial 5 L', 'quantity' => 10, 'unit_code' => 'EA', 'estimated_unit_price' => 12000],
                ['description' => 'Guantes de nitrilo (caja)', 'quantity' => 5, 'unit_code' => 'EA', 'estimated_unit_price' => 8000],
            ],
        ])->assertCreated()->json('data');
    }

    private function quote(string $id, string $supplier, array $prices, array $extra = []): void
    {
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/quotations", $extra + [
            'supplier_public_id' => $supplier,
            'valid_until' => '2026-12-15',
            'items' => [['line_number' => 1, 'unit_price' => $prices[0]], ['line_number' => 2, 'unit_price' => $prices[1]]],
        ])->assertCreated();
    }

    public function test_full_flow_from_request_to_receipt(): void
    {
        $r = $this->createRequest();
        $this->assertSame('SC-2026-0001', $r['number']);
        $this->assertSame('DRAFT', $r['status']['code']);
        $this->assertSame('160000.00', $r['estimated_total']);
        $id = $r['public_id'];

        $this->actingAs($this->buyer)->postJson("/api/v1/purchase-requests/{$id}/submit")->assertOk()->assertJsonPath('data.status.code', 'SUBMITTED');
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/start-quoting")->assertOk()->assertJsonPath('data.status.code', 'QUOTING');

        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/quotation-requests", ['supplier_public_id' => $this->supA, 'due_on' => '2026-11-20'])->assertCreated();

        // Alfa: 10×11.000 + 5×7.500 = 147.500 neto + despacho 5.000; IVA 19% de 152.500 = 28.975; total 181.475
        $this->quote($id, $this->supA, [11000, 7500], ['shipping_cost' => 5000, 'delivery_days' => 5]);
        // Beta: precios con IVA incluido: 10×13.090 + 5×8.330 = 172.550 → neto 145.000; IVA 27.550; total 172.550
        $this->quote($id, $this->supB, [13090, 8330], ['prices_include_tax' => true, 'delivery_days' => 2]);

        $detail = $this->actingAs($this->adminA)->getJson("/api/v1/purchase-requests/{$id}")->assertOk()->json('data');
        $alfa = collect($detail['quotations'])->firstWhere('supplier.public_id', $this->supA);
        $beta = collect($detail['quotations'])->firstWhere('supplier.public_id', $this->supB);
        $this->assertSame('181475.00', $alfa['total_clp']);
        $this->assertSame('145000.00', $beta['subtotal_net']);
        $this->assertSame('172550.00', $beta['total_clp']);
        $this->assertSame('RECEIVED', $detail['quotation_requests'][0]['status']);

        // Comparación: Beta es más barata y más rápida → recomendada. Instantánea con metodología.
        $cmp = $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/compare")->assertCreated()->json('data.comparison');
        $this->assertSame($beta['public_id'], $cmp['recommended_quotation_public_id']);
        $this->assertSame(0.6, $cmp['methodology']['weights']['price']);
        $this->assertNotEmpty($cmp['methodology']['assumptions']);
        $this->assertCount(2, $cmp['results']);

        // Elegir la no recomendada exige justificación.
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/propose", ['quotation_public_id' => $alfa['public_id']])
            ->assertStatus(422)->assertJsonPath('error.details.justification.0', 'Explica por qué no eliges la cotización recomendada.');
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/propose", ['quotation_public_id' => $beta['public_id']])
            ->assertOk()->assertJsonPath('data.status.code', 'PENDING_APPROVAL');

        // Quien propuso no aprueba.
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/decision", ['decision' => 'APPROVED'])->assertStatus(422);
        $this->actingAs($this->approver)->postJson("/api/v1/purchase-requests/{$id}/decision", ['decision' => 'APPROVED', 'comment' => 'OK'])
            ->assertOk()->assertJsonPath('data.status.code', 'APPROVED');
        $approval = PurchaseApproval::withoutTenantScope()->firstOrFail();
        $this->assertSame('172550.00', (string) $approval->amount_clp);
        $this->assertSame(1, $approval->request_version);

        // Orden interna con responsables explícitos.
        $order = $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/order", [
            'payer_name' => 'Empresa A', 'invoice_holder_name' => 'Empresa A', 'invoice_holder_rut' => '12.345.678-5',
            'contractual_buyer' => 'Empresa A', 'receiver_name' => 'Jefe de bodega',
        ])->assertCreated()->assertJsonPath('data.number', 'OCI-2026-0001')->assertJsonPath('data.total', '172550.00')->json('data');
        $this->assertCount(2, $order['items']);
        $this->actingAs($this->adminA)->getJson("/api/v1/purchase-requests/{$id}")->assertJsonPath('data.status.code', 'ORDERED');

        // Recepción parcial con incidencia, luego el resto.
        $oid = $order['public_id'];
        $this->actingAs($this->buyer)->postJson("/api/v1/purchase-orders/{$oid}/receipts", [
            'received_on' => '2026-11-18', 'items' => [['line_number' => 1, 'quantity_received' => 11]],
        ])->assertStatus(422); // más de lo pendiente
        $this->actingAs($this->buyer)->postJson("/api/v1/purchase-orders/{$oid}/receipts", [
            'received_on' => '2026-11-18', 'document_reference' => 'GD 123',
            'items' => [['line_number' => 1, 'quantity_received' => 8, 'quantity_rejected' => 2, 'incident_note' => '2 bidones rotos']],
        ])->assertOk()->assertJsonPath('data.status.code', 'PARTIALLY_RECEIVED')->assertJsonPath('data.receipts.0.has_incident', true);
        $this->actingAs($this->buyer)->postJson("/api/v1/purchase-orders/{$oid}/receipts", [
            'received_on' => '2026-11-18', 'items' => [['line_number' => 1, 'quantity_received' => 2], ['line_number' => 2, 'quantity_received' => 5]],
        ])->assertOk()->assertJsonPath('data.status.code', 'RECEIVED');
        $this->actingAs($this->buyer)->getJson("/api/v1/purchase-requests/{$id}")->assertJsonPath('data.status.code', 'CLOSED');

        $this->assertGreaterThanOrEqual(8, AuditLog::where('action', 'like', 'purchase_%')->count());
    }

    public function test_approval_limit_and_rejection_returns_to_quoting(): void
    {
        $id = $this->createRequest()['public_id'];
        $this->actingAs($this->buyer)->postJson("/api/v1/purchase-requests/{$id}/submit")->assertOk();
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/start-quoting")->assertOk();
        $this->quote($id, $this->supA, [500000, 100000]); // total > 5.000.000 CLP con IVA
        $q = $this->actingAs($this->adminA)->getJson("/api/v1/purchase-requests/{$id}")->json('data.quotations.0.public_id');
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/propose", ['quotation_public_id' => $q])->assertOk();

        $this->actingAs($this->approver)->postJson("/api/v1/purchase-requests/{$id}/decision", ['decision' => 'APPROVED'])
            ->assertStatus(422)->assertJsonPath('error.details.decision.0', fn ($m) => str_contains($m, 'supera tu límite'));

        $this->actingAs($this->approver)->postJson("/api/v1/purchase-requests/{$id}/decision", ['decision' => 'REJECTED'])->assertStatus(422); // sin motivo
        $this->actingAs($this->approver)->postJson("/api/v1/purchase-requests/{$id}/decision", ['decision' => 'REJECTED', 'comment' => 'Pide otra cotización'])
            ->assertOk()->assertJsonPath('data.status.code', 'QUOTING')->assertJsonPath('data.approvals.0.decision', 'REJECTED');

        $this->expectException(LogicException::class);
        PurchaseApproval::withoutTenantScope()->firstOrFail()->update(['comment' => 'x']);
    }

    public function test_expired_or_incomplete_quotations_are_not_recommended_nor_proposable(): void
    {
        $id = $this->createRequest()['public_id'];
        $this->actingAs($this->buyer)->postJson("/api/v1/purchase-requests/{$id}/submit");
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/start-quoting");
        $this->quote($id, $this->supA, [1, 1], ['valid_until' => '2026-11-01']); // muy barata pero vencida
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/quotations", [
            'supplier_public_id' => $this->supB, 'items' => [['line_number' => 1, 'unit_price' => 10000]], // incompleta
        ])->assertCreated();

        $data = $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/compare")->assertCreated()->json('data');
        $this->assertNull($data['comparison']['recommended_quotation_public_id']);
        $reasons = collect($data['comparison']['results'])->pluck('excluded_reasons')->flatten()->all();
        $this->assertContains('Cotización vencida', $reasons);
        $this->assertContains('Cubre 1 de 2 ítems', $reasons);

        $expired = collect($data['quotations'])->firstWhere('supplier.public_id', $this->supA);
        $this->assertTrue($expired['expired']);
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/propose", ['quotation_public_id' => $expired['public_id'], 'justification' => 'x'])->assertStatus(422);
        $this->assertSame(1, QuotationComparison::withoutTenantScope()->count());
    }

    public function test_foreign_currency_requires_exchange_rate(): void
    {
        $id = $this->createRequest()['public_id'];
        $this->actingAs($this->buyer)->postJson("/api/v1/purchase-requests/{$id}/submit");
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/start-quoting");

        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/quotations", [
            'supplier_public_id' => $this->supA, 'currency' => 'USD', 'items' => [['line_number' => 1, 'unit_price' => 10], ['line_number' => 2, 'unit_price' => 5]],
        ])->assertStatus(422);
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/quotations", [
            'supplier_public_id' => $this->supA, 'currency' => 'USD', 'exchange_rate_to_clp' => 950, 'exchange_rate_source' => 'Dólar observado',
            'tax_rate' => 0, 'items' => [['line_number' => 1, 'unit_price' => 10], ['line_number' => 2, 'unit_price' => 5]],
        ])->assertCreated()->assertJsonPath('data.quotations.0.total', '125.00')->assertJsonPath('data.quotations.0.total_clp', '118750.00');
    }

    public function test_permissions_states_and_tenant_isolation(): void
    {
        $id = $this->createRequest()['public_id'];
        $reader = $this->userWithTenantRole('tenant_read_only', $this->tenantA);

        $this->actingAs($reader)->postJson('/api/v1/purchase-requests', ['title' => 'X', 'items' => [['description' => 'X', 'quantity' => 1]]])->assertForbidden();
        $this->actingAs($this->buyer)->postJson("/api/v1/purchase-requests/{$id}/start-quoting")->assertForbidden();
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/quotations", ['supplier_public_id' => $this->supA, 'items' => [['line_number' => 1, 'unit_price' => 1]]])
            ->assertStatus(422); // aún en borrador
        $this->actingAs($this->adminB)->getJson("/api/v1/purchase-requests/{$id}")->assertNotFound();
        $this->actingAs($this->adminB)->getJson('/api/v1/purchase-requests')->assertJsonPath('meta.pagination.total', 0);

        // Edición sube la versión; tras cotizar ya no se edita.
        $this->actingAs($this->buyer)->patchJson("/api/v1/purchase-requests/{$id}", ['title' => 'Insumos de aseo (corregido)'])->assertOk()->assertJsonPath('data.version', 2);
        $this->actingAs($this->buyer)->postJson("/api/v1/purchase-requests/{$id}/submit");
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/start-quoting");
        $this->actingAs($this->buyer)->patchJson("/api/v1/purchase-requests/{$id}", ['title' => 'Otra'])->assertStatus(422);

        // Anular: el solicitante ya no puede (está en cotización); un aprobador sí, con motivo.
        $this->actingAs($this->buyer)->postJson("/api/v1/purchase-requests/{$id}/cancel", ['reason' => 'Ya no se necesita'])->assertForbidden();
        $this->actingAs($this->approver)->postJson("/api/v1/purchase-requests/{$id}/cancel", ['reason' => 'Ya no se necesita'])->assertOk()->assertJsonPath('data.status.code', 'CANCELLED');
    }

    public function test_numbering_is_per_tenant(): void
    {
        $this->createRequest();
        $this->createRequest();
        $b = $this->actingAs($this->adminB)->postJson('/api/v1/purchase-requests', ['title' => 'Compra B', 'items' => [['description' => 'Algo', 'quantity' => 1]]])->assertCreated()->json('data');

        $this->assertSame('SC-2026-0001', $b['number']);
        $this->assertNull($b['estimated_total']); // sin precio de referencia no se inventa estimado
        $this->actingAs($this->buyer)->getJson('/api/v1/purchase-requests')->assertJsonPath('data.0.number', 'SC-2026-0002');
    }

    public function test_approval_limit_is_managed_from_company_settings(): void
    {
        $this->actingAs($this->adminA)->patchJson("/api/v1/company/users/{$this->approver->public_id}", ['approval_limit_clp' => 1000000])->assertOk();
        $row = collect($this->actingAs($this->adminA)->getJson('/api/v1/company/users')->json('data'))->firstWhere('public_id', $this->approver->public_id);
        $this->assertSame('1000000.00', $row['approval_limit_clp']);
    }
}
