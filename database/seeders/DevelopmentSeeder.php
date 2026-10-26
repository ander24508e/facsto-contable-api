<?php

namespace Database\Seeders;

use App\Domains\Companies\Actions\ProvisionCompanyAction;
use App\Domains\Companies\DTOs\ProvisionAdministratorData;
use App\Domains\Companies\DTOs\ProvisionCompanyData;
use App\Domains\Companies\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/** One demo company, provisioned exactly like a real subscription. */
final class DevelopmentSeeder extends Seeder
{
    private const LOCAL_PASSWORD = 'FacstoLocal2026!';

    public function run(ProvisionCompanyAction $provision): void
    {
        if (Company::query()->where('slug', 'demo')->exists()) {
            return;
        }

        $result = $provision->execute(new ProvisionCompanyData(
            slug: 'demo',
            ruc: '1799999999001',
            mainAddress: 'Quito, Ecuador',
            legalName: 'Empresa demo FACSTO',
            email: 'facturacion@facsto.local',
            phone: null,
            operator: new ProvisionAdministratorData('Operador Condore', 'demo@facsto.local'),
            clientAdmin: new ProvisionAdministratorData('Administrador del cliente', 'cliente@facsto.local'),
        ));

        User::query()
            ->whereIn('email', array_keys($result['credentials']))
            ->update(['password' => Hash::make(self::LOCAL_PASSWORD)]);
    }
}
