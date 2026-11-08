<?php

namespace App\Domains\Companies\Policies;

use App\Domains\Companies\Access\CompanyAccess;
use App\Domains\Companies\Enums\CompanyStatus;
use App\Domains\Companies\Models\Company;
use App\Models\User;

/**
 * La empresa: la ve todo miembro activo (y con eso el Dashboard); su configuración (Mi Empresa, Sistema,
 * establecimientos, puntos de emisión, firma, integraciones) sólo el rol fijo Administrador.
 */
final class CompanyPolicy
{
    public function __construct(private readonly CompanyAccess $access) {}

    public function view(User $user, Company $company): bool
    {
        return $company->status === CompanyStatus::ACTIVE && $this->access->isMember($user, $company);
    }

    public function update(User $user, Company $company): bool
    {
        return $company->status === CompanyStatus::ACTIVE && $this->access->isAdministrator($user, $company);
    }
}
