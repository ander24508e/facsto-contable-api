<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\Models\Company;

final class UpdateCompanyAction
{
    public function execute(Company $company, array $attributes): Company
    {
        $company->fill($attributes)->save();

        return $company->refresh();
    }
}
