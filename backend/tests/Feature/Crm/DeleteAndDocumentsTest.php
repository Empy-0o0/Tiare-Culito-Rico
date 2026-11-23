<?php

namespace Tests\Feature\Crm;

use App\Models\PublicProcurementOpportunity;
use App\Models\Supplier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** TD-025: eliminar clientes/proveedores y copias de documentos de Mercado Público. */
class DeleteAndDocumentsTest extends CrmTestCase
{
    private function supplier(string $rut = '11.111.111-1'): string
    {
        return $this->actingAs($this->adminA)->postJson('/api/v1/suppliers', [
            'organization' => ['legal_name' => 'Proveedor Ficticio SpA', 'rut' => $rut, 'kind' => 'SME'],
        ])->assertCreated()->json('data.public_id');
    }

    // ---------------- Eliminar ----------------

    public function test_supplier_without_history_is_deleted_with_its_documents(): void
    {
        Storage::fake('local');
        $id = $this->supplier();
        $this->actingAs($this->adminA)->post("/api/v1/suppliers/{$id}/documents", [
            'file' => UploadedFile::fake()->create('cert.pdf', 20, 'application/pdf'), 'type' => 'OTHER', 'name' => 'Certificado',
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->assertCount(1, Storage::disk('local')->allFiles());

        $this->actingAs($this->adminA)->deleteJson("/api/v1/suppliers/{$id}")->assertOk();

        $this->assertDatabaseMissing('suppliers', ['public_id' => $id]);
        $this->assertDatabaseMissing('organizations', ['rut' => '11.111.111-1']);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->assertDatabaseHas('audit_logs', ['action' => 'suppliers.deleted', 'auditable_id' => $id]);
    }

    public function test_supplier_with_purchase_history_cannot_be_deleted(): void
    {
        $id = $this->supplier();
        $supplier = Supplier::withoutTenantScope()->where('public_id', $id)->first();
        $prId = DB::table('purchase_requests')->insertGetId(['public_id' => '01ARZ3NDEKTSV4RRFFQ69G5FA1', 'tenant_id' => $this->tenantA->id, 'number' => 'SC-1', 'title' => 'X', 'status' => 'QUOTING', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('quotation_requests')->insert(['public_id' => '01ARZ3NDEKTSV4RRFFQ69G5FA2', 'tenant_id' => $this->tenantA->id, 'purchase_request_id' => $prId, 'supplier_id' => $supplier->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->adminA)->deleteJson("/api/v1/suppliers/{$id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'HAS_HISTORY');
        $this->assertDatabaseHas('suppliers', ['public_id' => $id]);
    }

    public function test_customer_delete_keeps_organization_that_is_also_supplier(): void
    {
        $as = $this->actingAs($this->adminA);
        $customer = $as->postJson('/api/v1/customers', $this->customerPayload())->assertCreated()->json('data.public_id');
        $as->postJson('/api/v1/suppliers', $this->customerPayload())->assertCreated();

        $as->deleteJson("/api/v1/customers/{$customer}")->assertOk();
        $this->assertDatabaseMissing('customers', ['public_id' => $customer]);
        $this->assertDatabaseHas('organizations', ['rut' => '12.345.678-5']);
    }

    public function test_delete_requires_permission_and_same_tenant(): void
    {
        $id = $this->supplier();
        $reader = $this->userWithTenantRole('tenant_read_only', $this->tenantA);

        $this->actingAs($reader)->deleteJson("/api/v1/suppliers/{$id}")->assertStatus(403);
        $this->actingAs($this->adminB)->deleteJson("/api/v1/suppliers/{$id}")->assertStatus(404);
        $this->assertDatabaseHas('suppliers', ['public_id' => $id]);
    }

    // ---------------- Documentos de Mercado Público ----------------

    private function opportunity(): PublicProcurementOpportunity
    {
        $o = PublicProcurementOpportunity::create([
            'source_system' => 'mercado_publico', 'source_type' => 'compra_agil', 'source_api' => 'compra_agil_v2', 'source_api_version' => 'v2',
            'external_code' => '1234-5-COT26', 'title' => 'Compra ficticia',
        ]);
        $o->documents()->create(['external_id' => '987', 'name' => 'Bases técnicas.pdf']);

        return $o;
    }

    public function test_official_documents_are_listed_and_tenant_copies_are_private(): void
    {
        Storage::fake('local');
        $this->opportunity();
        $q = '?source_type=compra_agil&code=1234-5-COT26';

        $this->actingAs($this->adminA)->getJson('/api/v1/public-procurement/files'.$q)
            ->assertOk()
            ->assertJsonPath('data.official.0.name', 'Bases técnicas.pdf')
            ->assertJsonPath('meta.official_download_available', false);

        $file = $this->actingAs($this->adminA)->post('/api/v1/public-procurement/files', [
            'source_type' => 'compra_agil', 'code' => '1234-5-COT26', 'official_document_id' => '987',
            'file' => UploadedFile::fake()->create('bases.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.name', 'bases')
            ->assertJsonPath('data.data_origin', 'tenant')
            ->json('data.public_id');

        $this->actingAs($this->adminA)->get("/api/v1/public-procurement/files/{$file}/download")
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        // Otra empresa no ve ni descarga la copia.
        $this->actingAs($this->adminB)->getJson('/api/v1/public-procurement/files'.$q)->assertOk()->assertJsonCount(0, 'data.files');
        $this->actingAs($this->adminB)->get("/api/v1/public-procurement/files/{$file}/download", ['Accept' => 'application/json'])->assertStatus(404);
        $this->actingAs($this->adminB)->deleteJson("/api/v1/public-procurement/files/{$file}")->assertStatus(404);

        $this->actingAs($this->adminA)->deleteJson("/api/v1/public-procurement/files/{$file}")->assertOk();
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_document_upload_validation_and_permission(): void
    {
        Storage::fake('local');
        $this->opportunity();
        $reader = $this->userWithTenantRole('tenant_read_only', $this->tenantA);
        $payload = ['source_type' => 'compra_agil', 'code' => '1234-5-COT26', 'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')];

        $this->actingAs($reader)->post('/api/v1/public-procurement/files', $payload, ['Accept' => 'application/json'])->assertStatus(403);
        $this->actingAs($this->adminA)->post('/api/v1/public-procurement/files', ['file' => UploadedFile::fake()->create('x.exe', 10, 'application/x-msdownload')] + $payload, ['Accept' => 'application/json'])->assertStatus(422);
        $this->actingAs($this->adminA)->post('/api/v1/public-procurement/files', ['official_document_id' => 'otro'] + $payload, ['Accept' => 'application/json'])->assertStatus(422);
        $this->actingAs($this->adminA)->getJson('/api/v1/public-procurement/files?source_type=compra_agil&code=9999-1-COT26')->assertStatus(404);
    }
}
