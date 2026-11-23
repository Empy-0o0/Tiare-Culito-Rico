<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierDocument;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documentos y certificaciones de proveedores con vigencia (CLAUDE.md §5.3, §11).
 *
 * - Disco privado `local` (storage/app/private), ruta por tenant y proveedor, nombre aleatorio.
 * - Solo PDF, JPG y PNG hasta 10 MB; el tipo se verifica por contenido, no por la extensión enviada.
 * - Descarga solo a través de la API autenticada, como adjunto y con nosniff.
 * - Pendiente: análisis antimalware antes de producción (docs/SECURITY.md).
 */
class SupplierDocumentController extends Controller
{
    private const DISK = 'local';

    private const MIME_EXTENSIONS = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public function index(Request $request, Supplier $supplier): JsonResponse
    {
        Gate::authorize('view', $supplier);

        $docs = SupplierDocument::query()
            ->where('supplier_id', $supplier->getKey())
            ->when(! $request->boolean('include_archived'), fn ($q) => $q->whereNull('archived_at'))
            ->with('uploader:id,name')
            ->orderByRaw('expires_on IS NULL')
            ->orderBy('expires_on')
            ->get();

        return ApiResponse::ok($docs->map(fn (SupplierDocument $d) => $this->present($d, $supplier))->values(), [
            'types' => collect(SupplierDocument::TYPES)->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values(),
            'expiring_days' => SupplierDocument::EXPIRING_DAYS,
            'accepted' => 'PDF, JPG o PNG de hasta 10 MB',
        ]);
    }

    public function store(Request $request, Supplier $supplier, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('manageDocuments', $supplier);
        $v = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimetypes:'.implode(',', array_keys(self::MIME_EXTENSIONS))],
            'type' => ['required', Rule::in(array_keys(SupplierDocument::TYPES))],
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'issued_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issued_on'],
        ], [
            'file.mimetypes' => 'Solo se aceptan archivos PDF, JPG o PNG.',
            'file.max' => 'El archivo supera los 10 MB.',
        ]);

        $file = $request->file('file');
        $mime = (string) $file->getMimeType(); // detectado por contenido (fileinfo)
        $extension = self::MIME_EXTENSIONS[$mime] ?? null;
        if ($extension === null) {
            return ApiResponse::error('VALIDATION_FAILED', 'Revisa los campos indicados.', 422, ['file' => ['Solo se aceptan archivos PDF, JPG o PNG.']]);
        }

        $dir = 'tenants/'.$supplier->tenant_id.'/suppliers/'.$supplier->public_id;
        $path = Storage::disk(self::DISK)->putFileAs($dir, $file, Str::uuid()->toString().'.'.$extension);
        if ($path === false) {
            return ApiResponse::error('STORAGE_FAILED', 'No fue posible guardar el archivo. Intenta nuevamente.', 500);
        }

        try {
            $doc = DB::transaction(fn () => SupplierDocument::create([
                'supplier_id' => $supplier->getKey(),
                'uploaded_by' => $request->user()->getKey(),
                'type' => $v['type'],
                'name' => trim($v['name']),
                'issued_on' => $v['issued_on'] ?? null,
                'expires_on' => $v['expires_on'] ?? null,
                'storage_path' => $path,
                'original_filename' => Str::limit(basename(str_replace('\\', '/', (string) $file->getClientOriginalName())), 190, ''),
                'mime_type' => $mime,
                'size_bytes' => (int) $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()),
            ]));
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($path); // sin archivos huérfanos
            throw $e;
        }

        $audit->log('suppliers.document_uploaded', auditableType: 'supplier', auditableId: $supplier->public_id, metadata: ['document' => $doc->public_id, 'type' => $doc->type]);

        return ApiResponse::ok($this->present($doc->load('uploader:id,name'), $supplier), status: 201);
    }

    public function download(Supplier $supplier, string $document, AuditLogger $audit): StreamedResponse|JsonResponse
    {
        Gate::authorize('view', $supplier);
        $doc = $this->find($supplier, $document);
        if (! Storage::disk(self::DISK)->exists($doc->storage_path)) {
            return ApiResponse::error('RESOURCE_NOT_FOUND', 'El archivo ya no está disponible.', 404);
        }
        $audit->log('suppliers.document_downloaded', auditableType: 'supplier', auditableId: $supplier->public_id, metadata: ['document' => $doc->public_id]);

        $safeName = Str::slug(pathinfo($doc->original_filename, PATHINFO_FILENAME)) ?: 'documento';

        return Storage::disk(self::DISK)->download($doc->storage_path, $safeName.'.'.(self::MIME_EXTENSIONS[$doc->mime_type] ?? 'bin'), [
            'Content-Type' => $doc->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Archivar (reversible): deja de mostrarse y de contar para vigencias; el archivo se conserva. */
    public function archive(Request $request, Supplier $supplier, string $document, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('manageDocuments', $supplier);
        $doc = $this->find($supplier, $document);
        $archive = $request->boolean('archived', true);
        $doc->forceFill(['archived_at' => $archive ? now() : null])->save();
        $audit->log($archive ? 'suppliers.document_archived' : 'suppliers.document_restored', auditableType: 'supplier', auditableId: $supplier->public_id, metadata: ['document' => $doc->public_id]);

        return ApiResponse::ok($this->present($doc->load('uploader:id,name'), $supplier));
    }

    private function find(Supplier $supplier, string $publicId): SupplierDocument
    {
        return SupplierDocument::query()->where('supplier_id', $supplier->getKey())->where('public_id', $publicId)->firstOrFail();
    }

    private function present(SupplierDocument $d, Supplier $supplier): array
    {
        return [
            'public_id' => $d->public_id,
            'type' => ['code' => $d->type, 'label' => SupplierDocument::TYPES[$d->type] ?? $d->type],
            'name' => $d->name,
            'issued_on' => $d->issued_on?->toDateString(),
            'expires_on' => $d->expires_on?->toDateString(),
            'validity' => $d->validityStatus(),
            'file' => ['name' => $d->original_filename, 'mime_type' => $d->mime_type, 'size_bytes' => $d->size_bytes],
            'download_url' => '/api/v1/suppliers/'.$supplier->public_id.'/documents/'.$d->public_id.'/download',
            'uploaded_by' => $d->uploader?->name,
            'uploaded_at' => $d->created_at?->toIso8601String(),
            'archived' => $d->archived_at !== null,
            'data_origin' => 'client',
        ];
    }
}
