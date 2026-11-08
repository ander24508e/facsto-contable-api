<?php

namespace Tests\Support;

use App\Domains\Companies\Access\PermissionCatalog;
use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\Role;
use App\Domains\Companies\Services\CompanyRoles;
use App\Models\User;

/**
 * Roles for tests. Presets that behave like typical roles a company would create:
 *  - VIEWER: every «*.view» of the company's modules.
 *  - BILLER: views and operates every module, without Usuarios y roles, the IVA rates (sri.manage) or managing a
 *    vertical module (carwash.manage).
 *  - ADMIN: the fixed Administrador role.
 */
trait CreatesCompanyRoles
{
    protected function presetRole(Company $company, string $preset): Role
    {
        if ($preset === 'ADMIN' || $preset === 'OWNER') {
            return app(CompanyRoles::class)->administrator($company);
        }
        $keys = app(PermissionCatalog::class)->keys($company);
        $permissions = match ($preset) {
            'VIEWER' => array_values(array_filter($keys, fn (string $key) => str_ends_with($key, '.view'))),
            'BILLER' => array_values(array_filter($keys, fn (string $key) => ! str_starts_with($key, 'users.')
                && ! in_array($key, ['sri.manage', 'carwash.manage', 'carwash.assignable', 'carwash.own_work'], true))),
        };

        return $this->roleWith($company, $permissions, ucfirst(strtolower($preset)));
    }

    /** @param list<string> $permissions */
    protected function roleWith(Company $company, array $permissions, ?string $name = null): Role
    {
        $role = Role::query()->firstOrCreate(
            ['company_id' => $company->id, 'name' => $name ?? 'Rol '.(Role::query()->where('company_id', $company->id)->count() + 1)],
            ['is_active' => true],
        );
        $role->syncPermissions($permissions);

        return $role;
    }

    /** @return list<string> */
    protected function roleIds(Company $company, string $preset): array
    {
        return [$this->presetRole($company, $preset)->public_id];
    }

    /** A new active member with the preset role (or a role with the given permissions). */
    protected function memberAs(Company $company, string|array $presetOrPermissions): User
    {
        $user = User::factory()->create();
        $role = is_array($presetOrPermissions) ? $this->roleWith($company, $presetOrPermissions) : $this->presetRole($company, $presetOrPermissions);
        $membership = $company->memberships()->create(['user_id' => $user->id, 'is_active' => true, 'joined_at' => now()]);
        $membership->syncRoles([$role->id]);

        return $user;
    }
}
