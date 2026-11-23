<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PublicOpportunityFile;
use App\Models\PublicProcurementOpportunity;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use App\Support\CurrentTenant;
use App\Support\DocumentNameMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documentos de un proceso de Mercado Público (Compra Ágil o licitación) — TD-025.
 *
 * La API oficial (Compra Ágil V2) solo informa id y nombre de cada adjunto; no ofrece endpoint
 * de descarga. Por eso TIARE:
 *  - lista los adjuntos oficiales con su nombre y el acceso al portal, donde la persona los descarga;
 *  - guarda las copias que la empresa sube después de descargarlas, privadas por empresa,
 *    para que su equipo las descargue desde TIARE (CLAUDE.md §5.5: documentos que el usuario
 *    descargue legalmente y adjunte).
 *
 * Archivos: disco privado, nombre aleatorio, tipo verificado por contenido, descarga como adjunto con nosniff.
 * Pendiente antes de producción: antimalware (docs/SECURITY.md).
 */
class PublicOpportunityFileController extends Controller
{
    private const DISK = 'local';

    public const MIME_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    private const TYPES = [PublicProcurementOpportunity::TYPE_COMPRA_AGIL, PublicProcurementOpportunity::TYPE_LICITACION];

    public function index(Request $request): JsonResponse
    {
        $opportunity = $this->opportunity($request);
        $files = PublicOpportunityFile::query()->where('opportunity_id', $opportunity->getKey())->with('uploader:id,name')->latest('id')->get();
        $official = $opportunity->documents()->orderBy('id')->get(['external_id', 'name']);

        return ApiResponse::ok([
            'official' => $official->map(fn ($d) => ['id' => $d->external_id, 'name' => $d->name])->values(),
            'files' => $files->map(fn (PublicOpportunityFile $f) => $this->present($f))->values(),
        ], [
            'accepted' => 'PDF, Word, Excel, JPG o PNG de hasta 20 MB',
            'official_download_available' => false,
            'official_download_note' => 'La API oficial de ChileCompra informa el nombre de cada adjunto pero no entrega el archivo. Descárgalo desde Mercado Público y súbelo aquí para compartirlo con tu equipo.',
            'portal_url' => config('mercado_publico.portal.home_url'),
            // "Descargar todo" en ZIP; null si aún no hay copias (TD-030).
            'archive_url' => $files->isEmpty() ? null : '/api/v1/public-procurement/files-archive?'.http_build_query(['source_type' => $opportunity->source_type, 'code' => $opportunity->external_code]),
            'can_upload' => $request->user()->hasPermission('public_procurement.documents', app(CurrentTenant::class)->get()),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $opportunity = $this->opportunity($request);
        $v = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimetypes:'.implode(',', array_keys(self::MIME_EXTENSIONS))],
            'name' => ['nullable', 'string', 'max:200'],
            'official_document_id' => ['nullable', 'string', 'max:64'],
        ], [
            'file.mimetypes' => 'Solo se aceptan PDF, Word, Excel, JPG o PNG.',
            'file.max' => 'El archivo supera los 20 MB.',
        ]);

        if (! empty($v['official_document_id']) && ! $opportunity->documents()->where('external_id', $v['official_document_id'])->exists()) {
            return ApiResponse::error('VALIDATION_FAILED', 'Revisa los campos indicados.', 422, ['official_document_id' => ['El documento oficial indicado no pertenece a este proceso.']]);
        }

        $file = $request->file('file');
        $mime = (string) $file->getMimeType();
        $extension = self::MIME_EXTENSIONS[$mime] ?? null;
        if ($extension === null) {
            return ApiResponse::error('VALIDATION_FAILED', 'Revisa los campos indicados.', 422, ['file' => ['Solo se aceptan PDF, Word, Excel, JPG o PNG.']]);
        }

        $original = Str::limit(basename(str_replace('\\', '/', (string) $file->getClientOriginalName())), 190, '');
        $sha256 = hash_file('sha256', $file->getRealPath());

        // El mismo archivo ya se subió para este proceso: no se duplica (TD-030).
        $existing = PublicOpportunityFile::query()->where('opportunity_id', $opportunity->getKey())->where('sha256', $sha256)->with('uploader:id,name')->first();
        if ($existing) {
            return ApiResponse::ok($this->present($existing) + ['duplicate' => true, 'matched' => null]);
        }

        // Sin indicación explícita, se asocia al adjunto oficial de nombre más parecido (TD-030).
        $matched = null;
        if (empty($v['official_document_id'])) {
            $official = $opportunity->documents()->pluck('name', 'external_id')->all();
            $copied = PublicOpportunityFile::query()->where('opportunity_id', $opportunity->getKey())->whereNotNull('official_document_id')->pluck('official_document_id')->all();
            $matched = DocumentNameMatcher::bestMatch($original, $official, $copied);
            $v['official_document_id'] = $matched['id'] ?? null;
        }
        $tenantId = app(CurrentTenant::class)->id();
        $dir = 'tenants/'.$tenantId.'/public-procurement/'.$opportunity->public_id;
        $path = Storage::disk(self::DISK)->putFileAs($dir, $file, Str::uuid()->toString().'.'.$extension);
        if ($path === false) {
            return ApiResponse::error('STORAGE_FAILED', 'No fue posible guardar el archivo. Intenta nuevamente.', 500);
        }

        try {
            $doc = DB::transaction(fn () => PublicOpportunityFile::create([
                'opportunity_id' => $opportunity->getKey(),
                'official_document_id' => $v['official_document_id'] ?? null,
                'name' => trim((string) ($v['name'] ?? '')) ?: pathinfo($original, PATHINFO_FILENAME),
                'storage_path' => $path,
                'original_filename' => $original,
                'mime_type' => $mime,
                'size_bytes' => (int) $file->getSize(),
                'sha256' => $sha256,
                'uploaded_by' => $request->user()->getKey(),
            ]));
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($path);
            throw $e;
        }

        $audit->log('public_procurement.file_uploaded', auditableType: 'public_opportunity', auditableId: $opportunity->external_code, metadata: ['file' => $doc->public_id, 'auto_matched' => $matched !== null]);

        return ApiResponse::ok($this->present($doc->load('uploader:id,name')) + [
            'duplicate' => false,
            // Asociación automática con el adjunto oficial (null si se indicó a mano o no hubo coincidencia).
            'matched' => $matched,
        ], status: 201);
    }

    /**
     * "Descargar todo" (TD-030): un ZIP con las copias que la empresa guardó para este proceso.
     * Se arma en un temporal y se borra tras enviarlo.
     */
    public function archive(Request $request, AuditLogger $audit): BinaryFileResponse|JsonResponse
    {
        $opportunity = $this->opportunity($request);
        $files = PublicOpportunityFile::query()->where('opportunity_id', $opportunity->getKey())->orderBy('id')->get();
        if ($files->isEmpty()) {
            return ApiResponse::error('RESOURCE_NOT_FOUND', 'Aún no hay copias guardadas para este proceso.', 404);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'tiare-zip-');
        $zip = new \ZipArchive;
        if ($tmp === false || $zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            return ApiResponse::error('STORAGE_FAILED', 'No fue posible preparar el archivo comprimido.', 500);
        }
        $used = [];
        $added = 0;
        foreach ($files as $f) {
            $full = Storage::disk(self::DISK)->path($f->storage_path);
            if (! is_file($full)) {
                continue;
            }
            // Nombre seguro y único dentro del ZIP.
            $base = Str::slug(pathinfo($f->original_filename, PATHINFO_FILENAME)) ?: 'documento';
            $ext = self::MIME_EXTENSIONS[$f->mime_type] ?? 'bin';
            $entry = "{$base}.{$ext}";
            for ($i = 2; isset($used[$entry]); $i++) {
                $entry = "{$base}-{$i}.{$ext}";
            }
            $used[$entry] = true;
            $zip->addFile($full, $entry);
            $added++;
        }
        $zip->close();
        if ($added === 0) {
            @unlink($tmp);

            return ApiResponse::error('RESOURCE_NOT_FOUND', 'Los archivos ya no están disponibles.', 404);
        }

        $audit->log('public_procurement.files_archive_downloaded', auditableType: 'public_opportunity', auditableId: $opportunity->external_code, metadata: ['files' => $added]);

        return response()->download($tmp, Str::slug($opportunity->external_code).'-documentos.zip', [
            'Content-Type' => 'application/zip',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend(true);
    }

    public function download(string $file, AuditLogger $audit): StreamedResponse|JsonResponse
    {
        $doc = $this->find($file);
        if (! Storage::disk(self::DISK)->exists($doc->storage_path)) {
            return ApiResponse::error('RESOURCE_NOT_FOUND', 'El archivo ya no está disponible.', 404);
        }
        $audit->log('public_procurement.file_downloaded', auditableType: 'public_opportunity_file', auditableId: $doc->public_id);
        $safe = Str::slug(pathinfo($doc->original_filename, PATHINFO_FILENAME)) ?: 'documento';

        return Storage::disk(self::DISK)->download($doc->storage_path, $safe.'.'.(self::MIME_EXTENSIONS[$doc->mime_type] ?? 'bin'), [
            'Content-Type' => $doc->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroy(string $file, AuditLogger $audit): JsonResponse
    {
        $doc = $this->find($file);
        $path = $doc->storage_path;
        $doc->delete();
        Storage::disk(self::DISK)->delete($path);
        $audit->log('public_procurement.file_deleted', auditableType: 'public_opportunity_file', auditableId: $doc->public_id, metadata: ['name' => $doc->name]);

        return ApiResponse::ok(['deleted' => true]);
    }

    private function opportunity(Request $request): PublicProcurementOpportunity
    {
        $v = $request->validate([
            'source_type' => ['required', Rule::in(self::TYPES)],
            'code' => ['required', 'string', 'regex:/^[0-9A-Za-z\-]{3,64}$/'],
        ]);
        $opportunity = PublicProcurementOpportunity::query()
            ->where('source_system', PublicProcurementOpportunity::SOURCE_SYSTEM)
            ->where('source_type', $v['source_type'])
            ->where('external_code', $v['code'])
            ->first();
        abort_if($opportunity === null, 404);

        return $opportunity;
    }

    private function find(string $publicId): PublicOpportunityFile
    {
        // El global scope del tenant hace que una copia de otra empresa responda 404.
        return PublicOpportunityFile::query()->where('public_id', $publicId)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function present(PublicOpportunityFile $f): array
    {
        return [
            'public_id' => $f->public_id,
            'name' => $f->name,
            'official_document_id' => $f->official_document_id,
            'file' => ['name' => $f->original_filename, 'mime_type' => $f->mime_type, 'size_bytes' => $f->size_bytes],
            'download_url' => '/api/v1/public-procurement/files/'.$f->public_id.'/download',
            'uploaded_by' => $f->uploader?->name,
            'uploaded_at' => $f->created_at?->toIso8601String(),
            'data_origin' => 'tenant', // copia aportada por la empresa, no dato oficial
        ];
    }
}
