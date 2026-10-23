<?php

namespace Database\Seeders;

use App\Domains\Companies\Enums\CompanyRole;
use App\Domains\Companies\Models\Company;
use App\Domains\Identity\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DevelopmentTaxCatalogSeeder::class);

        $user = User::query()->firstOrCreate(
            ['email' => 'demo@facsto.local'],
            ['name' => 'Usuario demo FACSTO', 'password' => Hash::make('FacstoLocal2026!'), 'status' => UserStatus::ACTIVE],
        );

        $companyA = $this->company($user, '1799999999001', 'Empresa demo FACSTO A');
        $companyB = $this->company($user, '1799999999002', 'Empresa demo FACSTO B');

        $establishment = $companyA->establishments()->firstOrCreate(
            ['code' => '001'],
            ['name' => 'Matriz', 'address' => 'Quito, Ecuador', 'is_active' => true, 'is_main' => true],
        );
        $establishment->forceFill(['is_main' => true])->save();

        $emissionPoint = $establishment->emissionPoints()->firstOrCreate(
            ['code' => '001'],
            ['company_id' => $companyA->id, 'name' => 'Punto de emisión principal', 'is_active' => true, 'is_default' => true],
        );
        $emissionPoint->forceFill(['is_default' => true])->save();
    }

    private function company(User $owner, string $ruc, string $legalName): Company
    {
        $company = Company::query()->firstOrCreate(
            ['ruc' => $ruc],
            ['legal_name' => $legalName, 'main_address' => 'Ecuador'],
        );

        $company->memberships()->firstOrCreate(
            ['user_id' => $owner->id],
            ['role' => CompanyRole::OWNER, 'is_active' => true, 'joined_at' => now()],
        );

        return $company;
    }
}
