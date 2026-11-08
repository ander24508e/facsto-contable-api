<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\Access\CompanyAccess;
use App\Domains\Companies\Access\PermissionCatalog;
use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\Role;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Crea o edita un rol de la empresa: nombre único, observaciones, activo y sus permisos por módulo. El rol fijo
 * Administrador no se edita. Sólo se guardan permisos del catálogo de la empresa (núcleo y módulos que tiene).
 */
final class SaveRoleAction
{
    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly CompanyAccess $access,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array{name?: string, notes?: ?string, isActive?: bool, permissions?: list<string>} $data */
    public function execute(Company $company, User $actor, Role $role, array $data): Role
    {
        if ($role->exists && $role->is_fixed) {
            throw new ApiException(ApiErrorCode::ROLE_FIXED, 'El rol Administrador es fijo: no se edita ni se desactiva.', 422);
        }

        return DB::transaction(function () use ($company, $actor, $role, $data): Role {
            $creating = ! $role->exists;
            $before = $creating ? null : ['name' => $role->name, 'notes' => $role->notes, 'isActive' => $role->is_active, 'permissions' => $role->permissionKeys()];

            if (array_key_exists('name', $data)) {
                $name = trim(preg_replace('/\s+/u', ' ', $data['name']) ?? '');
                $taken = Role::query()->where('company_id', $company->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                    ->when($role->exists, fn ($query) => $query->whereKeyNot($role->id))->exists();
                if ($taken) {
                    throw new ApiException(ApiErrorCode::ROLE_DUPLICATE, 'Ya existe un rol con ese nombre.', 409, ['name' => ['Ya existe un rol con ese nombre.']]);
                }
                $role->name = $name;
            }
            if (array_key_exists('notes', $data)) {
                $role->notes = filled($data['notes']) ? trim((string) $data['notes']) : null;
            }
            if (array_key_exists('isActive', $data)) {
                $role->is_active = (bool) $data['isActive'];
            }
            $role->company_id = $company->id;
            $role->save();

            if (array_key_exists('permissions', $data)) {
                $unknown = array_diff($data['permissions'], $this->catalog->keys($company));
                if ($unknown !== []) {
                    throw ValidationException::withMessages(['permissions' => 'Hay permisos que no existen en los módulos de la empresa.']);
                }
                $role->syncPermissions(array_values($data['permissions']));
            }
            $this->access->forget();

            $after = ['name' => $role->name, 'notes' => $role->notes, 'isActive' => $role->is_active, 'permissions' => $role->permissionKeys()];
            $this->audit->record($actor, $company, $creating ? 'company.role_created' : 'company.role_updated', $role, $before, $after);

            return $role->refresh();
        });
    }
}
