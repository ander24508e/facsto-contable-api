<?php

namespace App\Providers;

use App\Domains\Carwash\Services\CatalogGuard;
use App\Domains\Modules\Commands\ModuleEntitlementCommand;
use App\Domains\Modules\Commands\PushEntitlementsCommand;
use App\Domains\Modules\Services\ModuleRegistry;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\VerifyPlatformSignature;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Vertical modules of FACSTO (Lavadora…) and the entitlements Condore Platform sends.
 *  - /internal/v1/platform/*: signed Platform API (routes/internal.php).
 *  - "module:{feature}" middleware: the company must have the module (READ_ONLY only reads).
 *  - Each module's actions (config/modules.php) are permissions of the company roles: PermissionCatalog shows them
 *    in Roles as the module's own card and AppServiceProvider defines their Gate «{module}.{action}».
 *  - Each module registers its API routes here (routes/api/{module}.php under /api/v1/{module}).
 * Core domains never reference a module (tests/Feature/Modules/ModuleBoundaryTest).
 */
final class ModulesServiceProvider extends ServiceProvider
{
    /** Modules with code in this build; a module listed in the manifest needs its routes file. */
    private const MODULE_ROUTES = ['carwash'];

    public function register(): void
    {
        $this->app->scoped(ModuleRegistry::class);
    }

    public function boot(): void
    {
        Route::aliasMiddleware('module', EnsureModuleEnabled::class);

        Route::middleware(['api', VerifyPlatformSignature::class])
            ->prefix('internal/v1')
            ->group(base_path('routes/internal.php'));

        foreach (self::MODULE_ROUTES as $module) {
            Route::middleware(['api', 'auth:sanctum', 'company.context', "module:{$module}"])
                ->prefix("api/v1/{$module}")
                ->group(base_path("routes/api/{$module}.php"));
        }

        // Lavadora: keeps Tarifas and the invoice in step by listening to Inventario's own item events.
        CatalogGuard::register();
        // Lavadora: «carwash.orders» opens Órdenes to «Ver» or to «Ver su propio trabajo».
        Gate::define('carwash.orders', fn (User $user): bool => Gate::forUser($user)->any(['carwash.view', 'carwash.own_work']));

        if ($this->app->runningInConsole()) {
            $this->commands([ModuleEntitlementCommand::class, PushEntitlementsCommand::class]);
        }
    }
}
