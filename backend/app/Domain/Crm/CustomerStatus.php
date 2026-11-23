<?php

namespace App\Domain\Crm;

enum CustomerStatus: string
{
    case Prospect = 'PROSPECT';
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';

    public function label(): string
    {
        return match ($this) {
            self::Prospect => 'Prospecto',
            self::Active => 'Activo',
            self::Inactive => 'Inactivo',
        };
    }
}
