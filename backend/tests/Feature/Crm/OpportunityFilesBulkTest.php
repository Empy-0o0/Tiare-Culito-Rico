<?php

namespace Tests\Feature\Crm;

use App\Models\PublicProcurementOpportunity;
use App\Support\DocumentNameMatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/** TD-030: subida múltiple con asociación automática al adjunto oficial y "Descargar todo" en ZIP. */
class OpportunityFilesBulkTest extends CrmTestCase
{
    private const Q = ['source_type' => 'compra_agil', 'code' => '5196-792-COT26'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $o = PublicProcurementOpportunity::create([
            'source_system' => 'mercado_publico', 'source_type' => 'compra_agil', 'source_api' => 'compra_agil_v2', 'source_api_version' => 'v2',
            'external_code' => '5196-792-COT26', 'title' => 'Compra ficticia',
        ]);
        $o->documents()->create(['external_id' => '111', 'name' => 'CONDICIONES OBLIGATORIAS DE PARTICIPACIÓN Y ADJUDICACIÓN COMPRA Á.pdf']);
        $o->documents()->create(['external_id' => '222', 'name' => 'Especificaciones técnicas.pdf']);
        $o->documents()->create(['external_id' => '333', 'name' => 'Anexo N°1 Formulario oferta.docx']);
    }

    private function upload(string $name, string $content, string $mime = 'application/pdf'): TestResponse
    {
        return $this->actingAs($this->adminA)->post('/api/v1/public-procurement/files', self::Q + [
            'file' => UploadedFile::fake()->createWithContent($name, $content)->mimeType($mime),
        ], ['Accept' => 'application/json']);
    }

    public function test_matcher_handles_accents_case_truncation_and_browser_suffixes(): void
    {
        $official = ['111' => 'CONDICIONES OBLIGATORIAS DE PARTICIPACIÓN Y ADJUDICACIÓN COMPRA Á.pdf', '222' => 'Especificaciones técnicas.pdf', '333' => 'Anexo N°1 Formulario oferta.docx'];

        $this->assertSame('111', DocumentNameMatcher::bestMatch('condiciones_obligatorias_de_participacion_y_adjudicacion_compra_a.pdf', $official)['id']);
        $this->assertSame('222', DocumentNameMatcher::bestMatch('Especificaciones tecnicas (1).pdf', $official)['id']);
        $this->assertSame('333', DocumentNameMatcher::bestMatch('ANEXO N 1 FORMULARIO OFERTA.DOCX', $official)['id']);
        $this->assertNull(DocumentNameMatcher::bestMatch('foto-de-la-bodega.jpg', $official));
    }

    public function test_bulk_upload_links_each_file_to_its_official_attachment(): void
    {
        $this->upload('condiciones obligatorias de participacion y adjudicacion compra a.pdf', '%PDF-1.4 uno')
            ->assertCreated()->assertJsonPath('data.official_document_id', '111')->assertJsonPath('data.matched.id', '111');
        $this->upload('Especificaciones_Tecnicas.pdf', '%PDF-1.4 dos')
            ->assertCreated()->assertJsonPath('data.official_document_id', '222');
        $this->upload('cotizacion-propia.pdf', '%PDF-1.4 tres')
            ->assertCreated()->assertJsonPath('data.official_document_id', null)->assertJsonPath('data.matched', null);

        $list = $this->actingAs($this->adminA)->getJson('/api/v1/public-procurement/files?'.http_build_query(self::Q))->assertOk();
        $this->assertCount(3, $list->json('data.files'));
        $this->assertStringContainsString('files-archive', $list->json('meta.archive_url'));
    }

    public function test_same_file_twice_is_not_duplicated(): void
    {
        $first = $this->upload('Especificaciones técnicas.pdf', '%PDF-1.4 igual')->assertCreated()->json('data.public_id');
        $this->upload('copia-renombrada.pdf', '%PDF-1.4 igual')->assertOk()->assertJsonPath('data.duplicate', true)->assertJsonPath('data.public_id', $first);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_download_all_as_zip_is_private_per_company(): void
    {
        $this->actingAs($this->adminA)->get('/api/v1/public-procurement/files-archive?'.http_build_query(self::Q), ['Accept' => 'application/json'])->assertStatus(404);

        $this->upload('Especificaciones técnicas.pdf', '%PDF-1.4 a');
        $this->upload('Especificaciones técnicas.pdf', '%PDF-1.4 b'); // mismo nombre, otro contenido
        $res = $this->actingAs($this->adminA)->get('/api/v1/public-procurement/files-archive?'.http_build_query(self::Q));
        $res->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('5196-792-cot26-documentos.zip', (string) $res->headers->get('Content-Disposition'));

        $zip = new \ZipArchive;
        $zip->open($res->baseResponse->getFile()->getPathname());
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        sort($names);
        $this->assertSame(['especificaciones-tecnicas-2.pdf', 'especificaciones-tecnicas.pdf'], $names);

        // Otra empresa no tiene copias: no descarga las ajenas.
        $this->actingAs($this->adminB)->get('/api/v1/public-procurement/files-archive?'.http_build_query(self::Q), ['Accept' => 'application/json'])->assertStatus(404);
    }
}
