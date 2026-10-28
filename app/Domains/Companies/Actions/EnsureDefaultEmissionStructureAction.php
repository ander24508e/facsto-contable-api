<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\EmissionPoint;
use App\Domains\Companies\Models\Establishment;
use Illuminate\Support\Facades\DB;

final class EnsureDefaultEmissionStructureAction
{
    /** @return array{establishment: ?Establishment, emissionPoint: ?EmissionPoint} */
    public function execute(Company $company): array
    {
        return DB::transaction(function () use ($company): array {
            $company = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $establishment = $company->establishments()->where('is_main', true)->first();

            if ($establishment === null) {
                $establishment = $company->establishments()->where('is_active', true)->orderBy('code')->first()
                    ?? $company->establishments()->orderBy('code')->first();

                if ($establishment === null && filled($company->main_address)) {
                    $establishment = $company->establishments()->create([
                        'code' => '001',
                        'name' => 'Matriz',
                        'address' => $company->main_address,
                        'is_active' => true,
                        'is_main' => true,
                    ]);
                } elseif ($establishment !== null) {
                    $establishment->forceFill(['is_active' => true, 'is_main' => true])->save();
                }
            } elseif (! $establishment->is_active) {
                $establishment->forceFill(['is_active' => true])->save();
            }

            if ($establishment === null) {
                return ['establishment' => null, 'emissionPoint' => null];
            }

            $point = $establishment->emissionPoints()->where('is_default', true)->first();
            if ($point !== null) {
                if (! $point->is_active) {
                    $point->forceFill(['is_active' => true])->save();
                }
            } else {
                $point = $establishment->emissionPoints()->where('is_active', true)->orderBy('code')->first()
                    ?? $establishment->emissionPoints()->orderBy('code')->first();

                if ($point !== null) {
                    $point->forceFill(['is_active' => true, 'is_default' => true])->save();
                } else {
                    $point = $establishment->emissionPoints()->create([
                        'company_id' => $company->id,
                        'code' => '001',
                        'name' => 'Principal',
                        'is_active' => true,
                        'is_default' => true,
                    ]);
                }
            }

            return [
                'establishment' => $establishment->refresh(),
                'emissionPoint' => $point->refresh()->load('establishment'),
            ];
        });
    }
}
