<?php

namespace App\Domains\Catalog\Policies;

use App\Domains\Companies\Access\ModulePolicy;

/** Marcas. */
final class BrandPolicy extends ModulePolicy
{
    protected const MODULE = 'brands';
}
