<?php

namespace Database\Seeders;

use App\Domains\Companies\Actions\EnsureInstanceAdministratorAction;
use App\Domains\Companies\Services\CompanyRoles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Runs on every production start (entrypoint → db:seed): one role per member, then the instance's company and
 * Condore's support account from SUPPORT_ADMIN_EMAIL / SUPPORT_ADMIN_PASSWORD. A missing or wrong variable never stops
 * the installation: it is reported in the api log and the support account is left as it was.
 */
final class InstanceAdministratorSeeder extends Seeder
{
    public function run(EnsureInstanceAdministratorAction $ensure, CompanyRoles $roles): void
    {
        $fixed = $roles->keepSingleRolePerMember();
        if ($fixed > 0) {
            $this->command?->info("{$fixed} usuario(s) con varios roles quedaron con uno solo.");
        }

        $email = (string) config('instance.support.email');
        $password = (string) config('instance.support.password');
        if ($email === '' || $password === '') {
            $this->command?->warn('Sin SUPPORT_ADMIN_EMAIL o SUPPORT_ADMIN_PASSWORD: no se crea la cuenta de soporte.');

            return;
        }

        try {
            $company = $ensure->execute((string) config('instance.company_slug'), (string) config('instance.support.name'), $email, $password);
        } catch (InvalidArgumentException $problem) {
            Log::warning('Cuenta de soporte sin crear ni actualizar: '.$problem->getMessage());
            $this->command?->warn('Cuenta de soporte sin crear ni actualizar: '.$problem->getMessage());

            return;
        }

        $this->command?->info("Empresa «{$company->slug}» y cuenta de soporte {$email} listas.");
    }
}
