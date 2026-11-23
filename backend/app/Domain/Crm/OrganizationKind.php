<?php

namespace App\Domain\Crm;

/** Clasificación de organizaciones (CLAUDE.md §5.2). */
enum OrganizationKind: string
{
    case Sme = 'SME';
    case MediumCompany = 'MEDIUM_COMPANY';
    case LargeCompany = 'LARGE_COMPANY';
    case StateSupplier = 'STATE_SUPPLIER';
    case PublicBuyer = 'PUBLIC_BUYER';
    case Institution = 'INSTITUTION';
    case LogisticsOperator = 'LOGISTICS_OPERATOR';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Sme => 'Pyme',
            self::MediumCompany => 'Empresa mediana',
            self::LargeCompany => 'Gran empresa',
            self::StateSupplier => 'Proveedor del Estado',
            self::PublicBuyer => 'Comprador público',
            self::Institution => 'Institución',
            self::LogisticsOperator => 'Operador logístico',
            self::Other => 'Otro',
        };
    }
}
