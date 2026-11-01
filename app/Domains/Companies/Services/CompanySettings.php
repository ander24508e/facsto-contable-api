<?php

namespace App\Domains\Companies\Services;

use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\CompanySetting;

/** The company's Sistema settings; a company that never opened Sistema gets the defaults. */
final class CompanySettings
{
    public function for(Company $company): CompanySetting
    {
        return CompanySetting::query()->firstOrNew(['company_id' => $company->id]);
    }

    public function lowStockThreshold(Company $company): int
    {
        return $this->for($company)->low_stock_threshold;
    }

    /** Whether a sale may leave a product below zero in its warehouse (Sistema → «Permitir stock negativo»). */
    public function allowsNegativeStock(Company $company): bool
    {
        return (bool) $this->for($company)->allow_negative_stock;
    }
}
