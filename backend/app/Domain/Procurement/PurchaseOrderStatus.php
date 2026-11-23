<?php

namespace App\Domain\Procurement;

/** Estado de una orden de compra interna de TIARE (no confundir con OC de Mercado Público). */
enum PurchaseOrderStatus: string
{
    case Issued = 'ISSUED';
    case Sent = 'SENT';
    case PartiallyReceived = 'PARTIALLY_RECEIVED';
    case Received = 'RECEIVED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'Emitida',
            self::Sent => 'Enviada al proveedor',
            self::PartiallyReceived => 'Recibida parcialmente',
            self::Received => 'Recibida',
            self::Cancelled => 'Anulada',
        };
    }

    public function canReceive(): bool
    {
        return in_array($this, [self::Issued, self::Sent, self::PartiallyReceived], true);
    }

    /** @return list<array{code:string,label:string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['code' => $s->value, 'label' => $s->label()], self::cases());
    }
}
