<?php

namespace App\Models;

use App\Domain\Crm\CustomerStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $fillable = ['organization_id', 'status', 'plan', 'source', 'owner_user_id', 'next_action_at', 'next_action_summary'];

    protected function casts(): array
    {
        return ['status' => CustomerStatus::class, 'next_action_at' => 'date:Y-m-d'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id')->withTrashed();
    }
}
