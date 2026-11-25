<?php

namespace Database\Seeders;

use App\Domains\Companies\Actions\EnsureInstanceAdministratorAction;
use Illuminate\Database\Seeder;

/**
 * Runs on every production start (entrypoint → db:seed): the instance's company and Condore's support account from
 * SUPPORT_ADMIN_EMAIL / SUPPORT_ADMIN_PASSWORD. Without both variables it does nothing.
 */
final class InstanceAdministratorSeeder extends Seeder
{
    public function run(EnsureInstanceAdministratorAction $ensure): void
    {
        $email = (string) config('instance.support.email');
        $password = (string) config('instance.support.password');
        if ($email === '' || $password === '') {
            $this->command?->warn('Sin SUPPORT_ADMIN_EMAIL o SUPPORT_ADMIN_PASSWORD: no se crea la cuenta de soporte.');

            return;
        }

        $company = $ensure->execute((string) config('instance.company_slug'), (string) config('instance.support.name'), $email, $password);
        $this->command?->info("Empresa «{$company->slug}» y cuenta de soporte {$email} listas.");
    }
}
