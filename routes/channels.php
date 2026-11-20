<?php

use App\Domains\Companies\Access\PermissionCatalog;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Modules\Enums\EntitlementStatus;
use App\Domains\Modules\Services\ModuleRegistry;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

/*
 * Regla 31: private channels of the live updates. The company is the one the server resolved for the session
 * (company.context), never the one the browser names. The notices carry no data (App\Shared\Realtime).
 */

// The company's core resources: any active member of that company.
Broadcast::channel('company.{company}', fn (User $user, string $company): bool => app(CompanyContext::class)->company()->public_id === $company);

// A vertical module's resources: the module on for the company (also read-only) and one of its permissions.
Broadcast::channel('company.{company}.module.{module}', function (User $user, string $company, string $module): bool {
    $current = app(CompanyContext::class)->company();
    $permissions = array_keys(app(PermissionCatalog::class)->blocks()[$module]['permissions'] ?? []);

    return $current->public_id === $company
        && array_key_exists($module, app(ModuleRegistry::class)->manifest())
        && app(ModuleRegistry::class)->status($current, $module) !== EntitlementStatus::DISABLED
        && $permissions !== [] && Gate::forUser($user)->any($permissions);
});

// One person's own notices (their roles or access changed).
Broadcast::channel('user.{userPublicId}', fn (User $user, string $userPublicId): bool => $user->public_id === $userPublicId);
