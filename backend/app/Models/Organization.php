<?php

namespace App\Models;

use App\Domain\Crm\OrganizationKind;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Organization extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = [
        'legal_name', 'trade_name', 'rut', 'kind', 'segment', 'email', 'phone', 'website',
        'address', 'commune', 'region_code', 'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'kind' => OrganizationKind::class,
            'region_code' => 'integer',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class)->orderByDesc('is_primary')->orderBy('full_name');
    }

    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class);
    }

    public function supplier(): HasOne
    {
        return $this->hasOne(Supplier::class);
    }
}
