<?php

namespace App\Providers;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Enums\CompanyRole;
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
 *  - Gate "{module}.{action}" for every action of the manifest (config/modules.php), by FACSTO role.
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

        foreach ((array) config('modules.modules', []) as $module => $definition) {
            foreach (array_keys($definition['permissions']) as $action) {
                Gate::define("{$module}.{$action}", fn (User $user): bool => app(ModuleRegistry::class)->allows($module, $action, $this->role($user)));
            }
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ModuleEntitlementCommand::class, PushEntitlementsCommand::class]);
        }
    }

    private function role(User $user): ?CompanyRole
    {
        $role = app(CompanyContext::class)->company()->memberships()
            ->where('user_id', $user->id)->where('is_active', true)->value('role');

        return $role instanceof CompanyRole ? $role : ($role ? CompanyRole::tryFrom((string) $role) : null);
    }
}
