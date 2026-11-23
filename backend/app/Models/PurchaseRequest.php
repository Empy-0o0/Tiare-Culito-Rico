<?php

namespace App\Models;

use App\Domain\Procurement\PurchaseRequestStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PurchaseRequest extends Model
{
    use BelongsToTenant, HasPublicId;

    public const PRIORITIES = ['LOW' => 'Baja', 'NORMAL' => 'Normal', 'HIGH' => 'Alta', 'URGENT' => 'Urgente'];

    protected $guarded = ['id', 'public_id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'status' => PurchaseRequestStatus::class,
            'needed_by' => 'immutable_date',
            'estimated_total' => 'decimal:2',
            'version' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'proposed_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class)->orderBy('line_number');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by')->withTrashed();
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by')->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function quotationRequests(): HasMany
    {
        return $this->hasMany(QuotationRequest::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(SupplierQuotation::class);
    }

    public function selectedQuotation(): BelongsTo
    {
        return $this->belongsTo(SupplierQuotation::class, 'selected_quotation_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(PurchaseApproval::class)->orderByDesc('id');
    }

    public function comparisons(): HasMany
    {
        return $this->hasMany(QuotationComparison::class)->orderByDesc('id');
    }

    public function order(): HasOne
    {
        return $this->hasOne(InternalPurchaseOrder::class);
    }
}
