<?php

namespace App\Domains\Customers\Policies;

use App\Domains\Companies\Access\ModulePolicy;

/** Clientes. */
final class CustomerPolicy extends ModulePolicy
{
    protected const MODULE = 'customers';
}
