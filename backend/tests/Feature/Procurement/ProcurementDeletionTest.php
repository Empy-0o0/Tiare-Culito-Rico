<?php

namespace Tests\Feature\Procurement;

use App\Models\InternalPurchaseOrder;
use App\Models\SupplierQuotation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Feature\Crm\CrmTestCase;

/** TD-031: eliminar órdenes internas y cotizaciones sin romper el historial. */
class ProcurementDeletionTest extends CrmTestCase
{
    private User $approver;

    private string $alfa;

    private string $beta;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-18 15:00:00');
        $this->approver = $this->userWithTenantRole('tenant_approver', $this->tenantA);
        $sup = fn ($n) => $this->actingAs($this->adminA)->postJson('/api/v1/suppliers', ['organization' => ['legal_name' => $n, 'kind' => 'SME']])->json('data.public_id');
        [$this->alfa, $this->beta] = [$sup('Alfa SpA'), $sup('Beta SpA')];
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Solicitud en cotización con dos cotizaciones. @return array{0: string, 1: string, 2: string} [solicitud, cotización Alfa, cotización Beta] */
    private function quotingRequest(): array
    {
        $id = $this->actingAs($this->adminA)->postJson('/api/v1/purchase-requests', ['title' => 'Insumos', 'items' => [
            ['description' => 'Detergente', 'quantity' => 10, 'estimated_unit_price' => 12000],
        ]])->json('data.public_id');
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/submit");
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/start-quoting");
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/quotation-requests", ['supplier_public_id' => $this->alfa])->assertCreated();
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/quotations", ['supplier_public_id' => $this->alfa, 'delivery_days' => 5, 'items' => [['line_number' => 1, 'unit_price' => 11000]]])->assertCreated();
        $q = $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/quotations", ['supplier_public_id' => $this->beta, 'delivery_days' => 2, 'items' => [['line_number' => 1, 'unit_price' => 10000]]])->json('data.quotations');

        return [$id, collect($q)->firstWhere('supplier.public_id', $this->alfa)['public_id'], collect($q)->firstWhere('supplier.public_id', $this->beta)['public_id']];
    }

    /** @return array{0: string, 1: string} [solicitud, orden] */
    private function orderedRequest(): array
    {
        [$id, , $beta] = $this->quotingRequest();
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/compare");
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/propose", ['quotation_public_id' => $beta])->assertOk();
        $this->actingAs($this->approver)->postJson("/api/v1/purchase-requests/{$id}/decision", ['decision' => 'APPROVED'])->assertOk();
        $order = $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/order", [
            'payer_name' => 'Empresa A', 'invoice_holder_name' => 'Empresa A', 'contractual_buyer' => 'Empresa A', 'receiver_name' => 'Bodega',
        ])->assertCreated()->json('data.public_id');

        return [$id, $order];
    }

    // ---------------- Cotizaciones ----------------

    public function test_quotation_is_deleted_while_quoting_and_rfq_returns_to_requested(): void
    {
        [$id, $alfa, $beta] = $this->quotingRequest();
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/compare")->assertCreated()
            ->assertJsonPath('data.comparison.recommended_quotation_public_id', $beta);

        $data = $this->actingAs($this->adminA)->deleteJson("/api/v1/purchase-requests/{$id}/quotations/{$beta}")->assertOk()->json('data');
        $this->assertCount(1, $data['quotations']);
        $this->assertNull(SupplierQuotation::withoutTenantScope()->where('public_id', $beta)->first());
        $this->assertDatabaseHas('audit_logs', ['action' => 'quotations.deleted']);

        // La recomendada ya no existe: proponer otra no exige justificación.
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/propose", ['quotation_public_id' => $alfa])->assertOk();
    }

    public function test_quotation_rfq_status_reverts_when_its_only_answer_is_deleted(): void
    {
        [$id, $alfa] = $this->quotingRequest();
        $this->actingAs($this->adminA)->deleteJson("/api/v1/purchase-requests/{$id}/quotations/{$alfa}")->assertOk()
            ->assertJsonPath('data.quotation_requests.0.status', 'REQUESTED');
    }

    public function test_quotation_cannot_be_deleted_after_quoting_or_without_permission(): void
    {
        [$id, $alfa, $beta] = $this->quotingRequest();
        $buyer = $this->userWithTenantRole('tenant_procurement_user', $this->tenantA);
        $this->actingAs($buyer)->deleteJson("/api/v1/purchase-requests/{$id}/quotations/{$alfa}")->assertForbidden();
        $this->actingAs($this->adminB)->deleteJson("/api/v1/purchase-requests/{$id}/quotations/{$alfa}")->assertNotFound();

        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/propose", ['quotation_public_id' => $beta, 'justification' => 'x'])->assertOk();
        $this->actingAs($this->adminA)->deleteJson("/api/v1/purchase-requests/{$id}/quotations/{$alfa}")->assertStatus(422);
        $this->actingAs($this->adminA)->deleteJson("/api/v1/purchase-requests/{$id}/quotations/{$beta}")->assertStatus(422);
    }

    // ---------------- Órdenes ----------------

    public function test_order_is_deleted_and_request_returns_to_approved_to_issue_a_new_one(): void
    {
        [$id, $oid] = $this->orderedRequest();
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-orders/{$oid}/sent")->assertOk();

        $this->actingAs($this->adminA)->deleteJson("/api/v1/purchase-orders/{$oid}")->assertOk()->assertJsonPath('data.deleted', true);
        $this->assertNull(InternalPurchaseOrder::withoutTenantScope()->where('public_id', $oid)->first());
        $this->actingAs($this->adminA)->getJson("/api/v1/purchase-requests/{$id}")->assertJsonPath('data.status.code', 'APPROVED');
        $this->assertDatabaseHas('audit_logs', ['action' => 'purchase_orders.deleted', 'auditable_id' => $oid]);

        // Se puede emitir una orden nueva; el número no se reutiliza.
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-requests/{$id}/order", [
            'payer_name' => 'Empresa A', 'invoice_holder_name' => 'Empresa A', 'contractual_buyer' => 'Empresa A', 'receiver_name' => 'Bodega',
        ])->assertCreated()->assertJsonPath('data.number', 'OCI-2026-0002');
    }

    public function test_cancelled_order_is_deleted_and_request_stays_cancelled(): void
    {
        [$id, $oid] = $this->orderedRequest();
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-orders/{$oid}/cancel", ['reason' => 'Error de digitación'])->assertOk();
        $this->actingAs($this->adminA)->deleteJson("/api/v1/purchase-orders/{$oid}")->assertOk();
        $this->actingAs($this->adminA)->getJson("/api/v1/purchase-requests/{$id}")->assertJsonPath('data.status.code', 'CANCELLED');
    }

    public function test_order_with_receipts_or_invoices_is_not_deleted(): void
    {
        [, $oid] = $this->orderedRequest();
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-orders/{$oid}/invoices", ['number' => '1001', 'issued_on' => '2026-11-18', 'net_amount' => 100000, 'tax_amount' => 19000])->assertCreated();
        $this->actingAs($this->adminA)->deleteJson("/api/v1/purchase-orders/{$oid}")->assertStatus(422);

        [, $oid2] = $this->orderedRequest();
        $this->actingAs($this->adminA)->postJson("/api/v1/purchase-orders/{$oid2}/receipts", ['received_on' => '2026-11-18', 'items' => [['line_number' => 1, 'quantity_received' => 1]]])->assertOk();
        $this->actingAs($this->adminA)->deleteJson("/api/v1/purchase-orders/{$oid2}")->assertStatus(422);
    }

    public function test_order_deletion_requires_permission_and_tenant(): void
    {
        [, $oid] = $this->orderedRequest();
        $buyer = $this->userWithTenantRole('tenant_procurement_user', $this->tenantA);
        $this->actingAs($buyer)->deleteJson("/api/v1/purchase-orders/{$oid}")->assertForbidden();
        $this->actingAs($this->adminB)->deleteJson("/api/v1/purchase-orders/{$oid}")->assertNotFound();
    }
}
