<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Copia privada (por empresa) de un documento de un proceso de Mercado Público, subida por una persona. */
class PublicOpportunityFile extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = ['opportunity_id', 'official_document_id', 'name', 'storage_path', 'original_filename', 'mime_type', 'size_bytes', 'sha256', 'uploaded_by'];

    protected $hidden = ['id', 'tenant_id', 'opportunity_id', 'storage_path', 'uploaded_by'];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(PublicProcurementOpportunity::class, 'opportunity_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }
}
