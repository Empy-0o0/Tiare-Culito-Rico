<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Documento o certificación de un proveedor, con vigencia. El archivo vive en el disco privado;
 * `storage_path` nunca se expone al cliente.
 */
class SupplierDocument extends Model
{
    use BelongsToTenant, HasPublicId;

    /** Tipos de documento. Lista operativa inicial (PEND-T08 definirá cuáles son exigibles). */
    public const TYPES = [
        'CERTIFICATION' => 'Certificación (ISO u otra)',
        'TAX' => 'Documento tributario',
        'LEGAL' => 'Escritura o vigencia de sociedad',
        'INSURANCE' => 'Póliza de seguro',
        'LICENSE' => 'Permiso o autorización sanitaria/municipal',
        'BANK' => 'Certificado bancario',
        'PRICE_LIST' => 'Lista de precios o catálogo',
        'OTHER' => 'Otro',
    ];

    public const EXPIRING_DAYS = 30;

    protected $guarded = ['id', 'public_id', 'tenant_id'];

    protected $hidden = ['storage_path'];

    protected function casts(): array
    {
        return [
            'issued_on' => 'immutable_date',
            'expires_on' => 'immutable_date',
            'archived_at' => 'immutable_datetime',
            'size_bytes' => 'integer',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }

    /** valid | expiring | expired | no_expiry (calculado en hora de Chile). */
    public function validityStatus(?CarbonImmutable $today = null): string
    {
        if ($this->expires_on === null) {
            return 'no_expiry';
        }
        $today ??= CarbonImmutable::now('America/Santiago')->startOfDay();
        $expires = CarbonImmutable::parse($this->expires_on->toDateString(), 'America/Santiago');

        return match (true) {
            $expires->lessThan($today) => 'expired',
            $expires->lessThanOrEqualTo($today->addDays(self::EXPIRING_DAYS)) => 'expiring',
            default => 'valid',
        };
    }
}
