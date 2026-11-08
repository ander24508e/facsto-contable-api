<?php

namespace App\Domains\Catalog\Policies;

use App\Domains\Companies\Access\ModulePolicy;

/** Inventario: productos (unidades e importación de productos usan los mismos permisos). */
final class ItemPolicy extends ModulePolicy
{
    protected const MODULE = 'inventory';
}
