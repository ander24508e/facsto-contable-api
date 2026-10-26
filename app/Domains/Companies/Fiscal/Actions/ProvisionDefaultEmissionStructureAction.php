<?php

namespace App\Domains\Companies\Fiscal\Actions;

use App\Domains\Companies\Fiscal\Enums\EmissionType;
use App\Domains\Companies\Fiscal\Enums\SriEnvironment;
use App\Domains\Companies\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Gives a newly registered company a usable emission structure: establishment
 * 001, emission point 001 and the SRI test environment. The client can change
 * them later from Mi Empresa.
 */
final class ProvisionDefaultEmissionStructureAction
{
    public function execute(Company $company): void
    {
        DB::transaction(function () use ($company): void {
            if ($company->establishments()->exists()) {
                return;
            }

            $establishment = $company->establishments()->create([
                'code' => '001',
                'name' => 'Matriz',
                'address' => $company->main_address,
                'is_active' => true,
                'is_main' => true,
            ]);

            $establishment->emissionPoints()->create([
                'company_id' => $company->id,
                'code' => '001',
                'name' => 'Principal',
                'is_active' => true,
                'is_default' => true,
            ]);

            $company->fiscalSettings()->firstOrNew()->fill([
                'sri_environment' => SriEnvironment::TEST,
                'emission_type' => EmissionType::NORMAL,
            ])->save();
        });
    }
}
