<?php

namespace App\Domain\Procurement;

/**
 * Ciclo de una solicitud de compra (CLAUDE.md §5.4):
 * DRAFT → SUBMITTED → QUOTING → PENDING_APPROVAL → APPROVED → ORDERED → CLOSED.
 * Un rechazo en PENDING_APPROVAL devuelve a QUOTING (la decisión queda registrada).
 * CANCELLED es posible antes de ORDERED.
 */
enum PurchaseRequestStatus: string
{
    case Draft = 'DRAFT';
    case Submitted = 'SUBMITTED';
    case Quoting = 'QUOTING';
    case PendingApproval = 'PENDING_APPROVAL';
    case Approved = 'APPROVED';
    case Ordered = 'ORDERED';
    case Closed = 'CLOSED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Submitted => 'Enviada',
            self::Quoting => 'En cotización',
            self::PendingApproval => 'Esperando aprobación',
            self::Approved => 'Aprobada',
            self::Ordered => 'Orden emitida',
            self::Closed => 'Recibida',
            self::Cancelled => 'Anulada',
        };
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Submitted], true);
    }

    public function isCancellable(): bool
    {
        return in_array($this, [self::Draft, self::Submitted, self::Quoting, self::PendingApproval, self::Approved], true);
    }

    /** @return list<array{code:string,label:string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['code' => $s->value, 'label' => $s->label()], self::cases());
    }
}
