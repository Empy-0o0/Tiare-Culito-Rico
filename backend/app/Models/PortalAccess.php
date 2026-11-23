<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Acceso de un cliente o proveedor a su portal (TD-028). */
class PortalAccess extends Model
{
    use BelongsToTenant, HasPublicId;

    public const CUSTOMER = 'customer';

    public const SUPPLIER = 'supplier';

    /** Rol de empresa por tipo de portal. */
    public const ROLES = [self::CUSTOMER => 'customer_portal', self::SUPPLIER => 'supplier_portal'];

    protected $fillable = ['user_id', 'party_type', 'customer_id', 'supplier_id', 'created_by'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
