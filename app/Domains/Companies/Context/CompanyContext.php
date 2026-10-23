<?php

namespace App\Domains\Companies\Context;

use App\Domains\Companies\Models\Company;
use LogicException;

final class CompanyContext
{
    private ?Company $company = null;

    public function set(Company $company): void
    {
        $this->company = $company;
    }

    public function company(): Company
    {
        return $this->company ?? throw new LogicException('No hay una empresa activa en este request.');
    }

    public function hasCompany(): bool
    {
        return $this->company !== null;
    }
}
