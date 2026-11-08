<?php

namespace App\Domains\Companies\Access;

use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\CompanyMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Lo que un usuario puede hacer en su empresa: el rol fijo Administrador puede todo; los demás, la suma de los
 * permisos de sus roles activos. Sin membresía activa no puede nada. Se calcula una vez por request.
 */
final class CompanyAccess
{
    /** @var array<string, array{admin: bool, permissions: list<string>}|null> */
    private array $resolved = [];

    public function __construct(private readonly PermissionCatalog $catalog) {}

    public function isMember(User $user, Company $company): bool
    {
        return $this->resolve($user, $company) !== null;
    }

    public function isAdministrator(User $user, Company $company): bool
    {
        return (bool) ($this->resolve($user, $company)['admin'] ?? false);
    }

    /** @return list<string> */
    public function permissions(User $user, Company $company): array
    {
        $access = $this->resolve($user, $company);
        if ($access === null) {
            return [];
        }
        $keys = $this->catalog->keys($company);

        return $access['admin'] ? $keys : array_values(array_intersect($keys, $access['permissions']));
    }

    public function allows(User $user, Company $company, string $permission): bool
    {
        return in_array($permission, $this->permissions($user, $company), true);
    }

    /**
     * Active members whose active roles grant the permission explicitly (being Administrador does not count): who
     * can be assigned to a job, for example.
     *
     * @return list<int> user ids
     */
    public function usersGranted(Company $company, string $permission): array
    {
        return DB::table('company_memberships as m')
            ->join('company_membership_roles as mr', 'mr.membership_id', '=', 'm.id')
            ->join('company_roles as r', 'r.id', '=', 'mr.role_id')
            ->join('company_role_permissions as p', 'p.role_id', '=', 'r.id')
            ->where('m.company_id', $company->id)
            ->where('m.is_active', true)
            ->where('r.is_active', true)
            ->where('p.permission', $permission)
            ->distinct()
            ->pluck('m.user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** After roles or memberships change in this request. */
    public function forget(): void
    {
        $this->resolved = [];
    }

    /** @return array{admin: bool, permissions: list<string>}|null */
    private function resolve(User $user, Company $company): ?array
    {
        $key = $company->id.':'.$user->id;
        if (! array_key_exists($key, $this->resolved)) {
            $membership = CompanyMembership::query()->where('company_id', $company->id)->where('user_id', $user->id)->where('is_active', true)->first();
            $this->resolved[$key] = $membership === null ? null : [
                'admin' => $membership->isAdministrator(),
                'permissions' => DB::table('company_membership_roles as mr')
                    ->join('company_roles as r', 'r.id', '=', 'mr.role_id')
                    ->join('company_role_permissions as p', 'p.role_id', '=', 'r.id')
                    ->where('mr.membership_id', $membership->id)
                    ->where('r.is_active', true)
                    ->distinct()
                    ->pluck('p.permission')
                    ->all(),
            ];
        }

        return $this->resolved[$key];
    }
}
