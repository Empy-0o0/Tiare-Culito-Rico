<?php

namespace App\Domain\Crm;

/**
 * Estado del proveedor. Deliberadamente no existe "validado": CLAUDE.md §5.3 exige criterios
 * y evidencia definidos antes de presentar un proveedor como validado (pendiente PEND-T08).
 */
enum SupplierStatus: string
{
    case Registered = 'REGISTERED';
    case UnderReview = 'UNDER_REVIEW';
    case Approved = 'APPROVED_FOR_QUOTES';
    case Suspended = 'SUSPENDED';

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Registrado',
            self::UnderReview => 'En revisión',
            self::Approved => 'Habilitado para cotizar',
            self::Suspended => 'Suspendido',
        };
    }
}
