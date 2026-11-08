<?php

namespace App\Domains\Sales\Policies;

use App\Domains\Companies\Access\ModulePolicy;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Ventas: «sales.create» crea, factura, edita el borrador y anula. */
final class SalePolicy extends ModulePolicy
{
    protected const MODULE = 'sales';

    public function update(User $user, Model $record): bool
    {
        return $this->allows($user, 'create') && $this->owns($record);
    }
}
