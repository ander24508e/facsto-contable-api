<?php

namespace App\Domains\Companies\Services;

use App\Domains\Companies\Access\CompanyAccess;
use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\CompanyMembership;
use App\Domains\Companies\Models\Role;
use App\Models\User;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de los roles de una empresa:
 *  - «Administrador» (fijo) existe siempre y la empresa conserva al menos un usuario activo con él.
 *  - Sólo un Administrador da o quita el rol Administrador, o cambia a un usuario que lo tiene.
 *  - Un usuario lleva uno o varios roles de su empresa, activos (o los que ya tenía).
 */
final class CompanyRoles
{
    public function __construct(private readonly CompanyAccess $access) {}

    public function administrator(Company $company): Role
    {
        return Role::query()->firstOrCreate(
            ['company_id' => $company->id, 'is_fixed' => true],
            ['name' => Role::ADMINISTRATOR, 'is_active' => true],
        );
    }

    /** Gives the membership the Administrador role (new company, provisioning, demo). */
    public function makeAdministrator(CompanyMembership $membership): void
    {
        $membership->roles()->syncWithoutDetaching([$this->administrator($membership->company)->id => ['company_id' => $membership->company_id]]);
        $this->access->forget();
    }

    /**
     * @param  list<string>  $publicIds
     * @param  list<int>  $current  roles the membership already has (kept even if turned off later)
     * @return Collection<int, Role>
     */
    public function resolve(Company $company, array $publicIds, array $current = []): Collection
    {
        $roles = Role::query()->where('company_id', $company->id)->whereIn('public_id', array_values(array_unique($publicIds)))->get();
        if ($roles->count() !== count(array_unique($publicIds)) || $roles->contains(fn (Role $role) => ! $role->is_active && ! in_array($role->id, $current, true))) {
            throw ValidationException::withMessages(['rolePublicIds' => 'Elige roles activos de la empresa.']);
        }

        return $roles;
    }

    /**
     * Assigns the roles; the actor must be Administrador to give or take the Administrador role, or to change a user
     * who has it. The company keeps at least one active Administrador.
     *
     * @param  Collection<int, Role>  $roles
     */
    public function assign(Company $company, User $actor, CompanyMembership $membership, Collection $roles): void
    {
        $wasAdministrator = $membership->exists && $membership->isAdministrator();
        $willBeAdministrator = $roles->contains(fn (Role $role) => $role->is_fixed);
        if (($wasAdministrator || $willBeAdministrator) && ! $this->access->isAdministrator($actor, $company)) {
            throw new ApiException(ApiErrorCode::COMPANY_ACCESS_DENIED, 'Sólo un Administrador puede dar, quitar o cambiar el rol Administrador.', 403);
        }
        if ($wasAdministrator && ! $willBeAdministrator && $membership->is_active) {
            $this->assertKeepsAdministrator($company, $membership);
        }
        $membership->syncRoles($roles->pluck('id')->all());
        $this->access->forget();
    }

    /** The actor may change this member: an Administrador member is changed only by an Administrador. */
    public function assertCanManage(Company $company, User $actor, CompanyMembership $membership): void
    {
        if ($membership->isAdministrator() && ! $this->access->isAdministrator($actor, $company)) {
            throw new ApiException(ApiErrorCode::COMPANY_ACCESS_DENIED, 'Sólo un Administrador puede cambiar a otro Administrador.', 403);
        }
    }

    public function assertKeepsAdministrator(Company $company, CompanyMembership $leaving): void
    {
        $others = CompanyMembership::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->whereKeyNot($leaving->id)
            ->whereHas('roles', fn ($roles) => $roles->where('company_roles.is_fixed', true))
            ->exists();
        if (! $others) {
            throw new ApiException(ApiErrorCode::LAST_ADMINISTRATOR_REQUIRED, 'La empresa debe conservar al menos un Administrador activo.', 409);
        }
    }
}
