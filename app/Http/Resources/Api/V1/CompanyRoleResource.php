<?php

namespace App\Http\Resources\Api\V1;

use App\Domains\Companies\Access\PermissionCatalog;
use App\Domains\Companies\Context\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Rol de la empresa. Administrador (fijo) lleva todos los permisos de la empresa. */
final class CompanyRoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'name' => $this->name,
            'notes' => $this->notes,
            'isFixed' => $this->is_fixed,
            'isActive' => $this->is_active,
            'usersCount' => $this->whenCounted('users_count'),
            'permissions' => $this->is_fixed
                ? app(PermissionCatalog::class)->keys(app(CompanyContext::class)->company())
                : $this->permissionKeys(),
        ];
    }
}
