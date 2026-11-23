<?php

namespace Tests\Feature\Crm;

use App\Models\SupplierDocument;
use App\Models\SupplierEvaluation;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;

class SupplierEvaluationAndDocumentTest extends CrmTestCase
{
    private string $supplierId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        CarbonImmutable::setTestNow('2026-11-17 15:00:00');
        $this->supplierId = $this->actingAs($this->adminA)->postJson('/api/v1/suppliers', [
            'organization' => ['legal_name' => 'Proveedor Ficticio SpA', 'kind' => 'SME'],
        ])->assertCreated()->json('data.public_id');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function evaluation(array $overrides = []): array
    {
        return $overrides + ['evaluated_on' => '2026-11-10', 'price_score' => 4, 'quality_score' => 5, 'delivery_score' => 3, 'compliance_score' => 4, 'service_score' => 4, 'reference' => 'OC-123'];
    }

    public function test_evaluation_is_recorded_with_simple_average_and_summary(): void
    {
        $this->actingAs($this->adminA)->postJson("/api/v1/suppliers/{$this->supplierId}/evaluations", $this->evaluation())
            ->assertCreated()
            ->assertJsonPath('data.overall', '4.00')
            ->assertJsonPath('meta.summary.count', 1);

        $this->actingAs($this->adminA)->postJson("/api/v1/suppliers/{$this->supplierId}/evaluations", $this->evaluation(['price_score' => 2, 'quality_score' => 2, 'delivery_score' => 2, 'compliance_score' => 2, 'service_score' => 2, 'had_incident' => true, 'comment' => 'Entrega incompleta']))
            ->assertCreated();

        $this->actingAs($this->adminA)->getJson("/api/v1/suppliers/{$this->supplierId}/evaluations")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.summary.overall', '3.00')
            ->assertJsonPath('meta.summary.incidents', 1)
            ->assertJsonPath('meta.summary.by_criterion.price_score', '3.00');

        // El listado de proveedores muestra el promedio.
        $this->actingAs($this->adminA)->getJson('/api/v1/suppliers')
            ->assertJsonPath('data.0.evaluation.count', 2)
            ->assertJsonPath('data.0.evaluation.average', '3.00');
    }

    public function test_evaluation_validation(): void
    {
        $url = "/api/v1/suppliers/{$this->supplierId}/evaluations";
        $this->actingAs($this->adminA)->postJson($url, $this->evaluation(['quality_score' => 6]))->assertStatus(422);
        $this->actingAs($this->adminA)->postJson($url, $this->evaluation(['evaluated_on' => '2026-12-01']))->assertStatus(422);
        $this->actingAs($this->adminA)->postJson($url, $this->evaluation(['had_incident' => true]))
            ->assertStatus(422)->assertJsonPath('error.details.comment.0', 'Describe el incidente en el comentario.');
    }

    public function test_evaluations_are_immutable(): void
    {
        $this->actingAs($this->adminA)->postJson("/api/v1/suppliers/{$this->supplierId}/evaluations", $this->evaluation())->assertCreated();
        $evaluation = SupplierEvaluation::withoutTenantScope()->firstOrFail();

        $this->expectException(LogicException::class);
        $evaluation->update(['price_score' => 1]);
    }

    public function test_evaluate_requires_permission_and_tenant(): void
    {
        $reader = $this->userWithTenantRole('tenant_read_only', $this->tenantA);
        $url = "/api/v1/suppliers/{$this->supplierId}/evaluations";

        $this->actingAs($reader)->getJson($url)->assertOk();
        $this->actingAs($reader)->postJson($url, $this->evaluation())->assertForbidden();
        $this->actingAs($this->adminB)->getJson($url)->assertNotFound();
        $this->actingAs($this->adminB)->postJson($url, $this->evaluation())->assertNotFound();
    }

    public function test_document_upload_validity_and_download(): void
    {
        $url = "/api/v1/suppliers/{$this->supplierId}/documents";

        $this->actingAs($this->adminA)->post($url, [
            'file' => UploadedFile::fake()->create('certificado ISO.pdf', 200, 'application/pdf'),
            'type' => 'CERTIFICATION',
            'name' => 'ISO 9001',
            'issued_on' => '2025-01-01',
            'expires_on' => '2026-12-01', // dentro de 30 días → por vencer
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.validity', 'expiring')
            ->assertJsonPath('data.type.label', 'Certificación (ISO u otra)')
            ->assertJsonMissingPath('data.storage_path');

        $this->actingAs($this->adminA)->post($url, [
            'file' => UploadedFile::fake()->create('patente.png', 50, 'image/png'), // sin GD en el entorno local
            'type' => 'LICENSE',
            'name' => 'Patente municipal',
            'expires_on' => '2026-10-01',
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.validity', 'expired');

        $list = $this->actingAs($this->adminA)->getJson($url)->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame('expired', $list->json('data.0.validity')); // ordenado por vencimiento

        $doc = SupplierDocument::withoutTenantScope()->where('name', 'ISO 9001')->firstOrFail();
        Storage::disk('local')->assertExists($doc->storage_path);
        $this->assertStringStartsWith("tenants/{$this->tenantA->id}/suppliers/{$this->supplierId}/", $doc->storage_path);

        $this->actingAs($this->adminA)->get("{$url}/{$doc->public_id}/download")
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDownload('certificado-iso.pdf');

        // El listado de proveedores marca documentos vencidos.
        $this->actingAs($this->adminA)->getJson('/api/v1/suppliers')->assertJsonPath('data.0.expired_documents', 1);
    }

    public function test_document_upload_rejects_invalid_files(): void
    {
        $url = "/api/v1/suppliers/{$this->supplierId}/documents";
        $base = ['type' => 'OTHER', 'name' => 'Archivo'];

        $this->actingAs($this->adminA)->post($url, $base + ['file' => UploadedFile::fake()->create('malo.exe', 10, 'application/x-msdownload')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.details.file.0', 'Solo se aceptan archivos PDF, JPG o PNG.');
        $this->actingAs($this->adminA)->post($url, $base + ['file' => UploadedFile::fake()->create('grande.pdf', 11000, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.details.file.0', 'El archivo supera los 10 MB.');
        $this->actingAs($this->adminA)->post($url, $base + ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), 'issued_on' => '2026-05-01', 'expires_on' => '2026-01-01'], ['Accept' => 'application/json'])
            ->assertStatus(422);
        Storage::disk('local')->assertDirectoryEmpty('/');
    }

    public function test_document_access_is_isolated_and_archivable(): void
    {
        $url = "/api/v1/suppliers/{$this->supplierId}/documents";
        $publicId = $this->actingAs($this->adminA)->post($url, ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), 'type' => 'TAX', 'name' => 'Carpeta tributaria'], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.public_id');

        $this->actingAs($this->adminB)->get("{$url}/{$publicId}/download", ['Accept' => 'application/json'])->assertNotFound();
        $reader = $this->userWithTenantRole('tenant_read_only', $this->tenantA);
        $this->actingAs($reader)->post($url, ['file' => UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'), 'type' => 'TAX', 'name' => 'X'], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($reader)->get("{$url}/{$publicId}/download")->assertOk();

        $this->actingAs($this->adminA)->patchJson("{$url}/{$publicId}", ['archived' => true])->assertOk()->assertJsonPath('data.archived', true);
        $this->actingAs($this->adminA)->getJson($url)->assertJsonCount(0, 'data');
        $this->actingAs($this->adminA)->getJson($url.'?include_archived=1')->assertJsonCount(1, 'data');
    }
}
