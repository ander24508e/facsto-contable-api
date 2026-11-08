<?php

namespace App\Domains\Taxes\Policies;

use App\Domains\Companies\Access\ModulePolicy;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Tarifas de IVA de la empresa: todo miembro las consulta (las usan Ventas, Inventario, Compras…); sólo «sri.manage»
 * elige cuáles aplica la empresa.
 */
final class CompanyTaxPolicy extends ModulePolicy
{
    protected const MODULE = 'sri';

    public function viewAny(User $user): bool
    {
        return $this->access->isMember($user, $this->context->company());
    }

    public function manage(User $user): bool
    {
        return $this->allows($user, 'manage');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->manage($user) && $this->owns($record);
    }
}
