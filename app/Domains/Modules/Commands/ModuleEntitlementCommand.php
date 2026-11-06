<?php

namespace App\Domains\Modules\Commands;

use App\Domains\Companies\Models\Company;
use App\Domains\Modules\Enums\EntitlementSource;
use App\Domains\Modules\Enums\EntitlementStatus;
use App\Domains\Modules\Models\CompanyEntitlement;
use App\Domains\Modules\Models\CompanyEntitlementState;
use App\Domains\Modules\Models\EntitlementSync;
use App\Domains\Modules\Services\ModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Changes one module feature of a company by hand, while Condore Platform does not exist (soporte).
 * The next full message from Platform replaces it.
 *   php artisan modules:entitlement mi-lavadora carwash
 *   php artisan modules:entitlement 1790012345001 carwash --read-only
 *   php artisan modules:entitlement mi-lavadora carwash.storefront --disable
 */
final class ModuleEntitlementCommand extends Command
{
    protected $signature = 'modules:entitlement {company : slug o RUC de la empresa} {feature : carwash, carwash.storefront…} {--read-only : sólo consulta} {--disable : quitar el derecho}';

    protected $description = 'Habilita, pone en solo lectura o quita un módulo (o función) a una empresa';

    public function handle(ModuleRegistry $modules): int
    {
        $feature = (string) $this->argument('feature');
        if (! in_array($feature, $modules->featureKeys(), true)) {
            $this->error('Función desconocida. Disponibles: '.implode(', ', $modules->featureKeys()));

            return self::FAILURE;
        }
        $key = (string) $this->argument('company');
        $company = Company::query()->where('slug', $key)->orWhere('ruc', $key)->first();
        if (! $company) {
            $this->error('No existe una empresa con ese slug o RUC.');

            return self::FAILURE;
        }
        $status = $this->option('disable') ? EntitlementStatus::DISABLED : ($this->option('read-only') ? EntitlementStatus::READ_ONLY : EntitlementStatus::ENABLED);

        DB::transaction(function () use ($company, $feature, $status): void {
            CompanyEntitlement::query()->updateOrCreate(
                ['company_id' => $company->id, 'feature' => $feature],
                ['status' => $status, 'source' => EntitlementSource::CONSOLE, 'valid_until' => null],
            );
            EntitlementSync::query()->create([
                'company_id' => $company->id,
                'version' => (int) CompanyEntitlementState::query()->where('company_id', $company->id)->value('version'),
                'source' => EntitlementSource::CONSOLE->value,
                'result' => 'APPLIED',
                'payload' => ['feature' => $feature, 'status' => $status->value],
                'created_at' => now(),
            ]);
        });
        $this->info(sprintf('%s: %s → %s.', $company->slug, $feature, $status->value));

        return self::SUCCESS;
    }
}
