<?php

namespace App\Domains\Catalog\Policies;

use App\Domains\Companies\Access\ModulePolicy;

/** Categorías. */
final class CategoryPolicy extends ModulePolicy
{
    protected const MODULE = 'categories';
}
