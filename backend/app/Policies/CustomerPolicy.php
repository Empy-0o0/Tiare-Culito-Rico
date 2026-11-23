<?php

namespace App\Policies;

class CustomerPolicy extends TenantResourcePolicy
{
    protected function resource(): string
    {
        return 'customers';
    }
}
