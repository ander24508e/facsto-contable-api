<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Access\PermissionCatalog;
use App\Domains\Companies\Actions\SaveRoleAction;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Role;
use App\Domains\Companies\Services\CompanyRoles;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CompanyRoleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Roles de la empresa (Usuarios y roles): lista con búsqueda y estado, crear y editar con sus permisos por módulo.
 * «modules» devuelve las tarjetas de la pantalla: un bloque por módulo con sus propios permisos.
 */
final class CompanyRoleController extends Controller
{
    public function index(Request $request, CompanyContext $context, CompanyRoles $roles): AnonymousResourceCollection
    {
        $this->authorize('users.view');
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:80'],
            'status' => ['sometimes', 'nullable', 'in:ACTIVE,INACTIVE,ALL'],
        ]);
        $company = $context->company();
        $roles->administrator($company);

        return CompanyRoleResource::collection(Role::query()
            ->where('company_id', $company->id)
            ->withCount(['memberships as users_count' => fn ($query) => $query->where('company_memberships.is_active', true)])
            ->when(filled($filters['search'] ?? null), fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower(trim($filters['search'])).'%']))
            ->when(in_array($filters['status'] ?? 'ALL', ['ACTIVE', 'INACTIVE'], true), fn ($query) => $query->where('is_active', $filters['status'] === 'ACTIVE'))
            ->ordered()
            ->get());
    }

    public function modules(CompanyContext $context, PermissionCatalog $catalog): JsonResponse
    {
        $this->authorize('users.view');
        $blocks = [];
        foreach ($catalog->forCompany($context->company()) as $module => $block) {
            $blocks[] = [
                'module' => $module,
                'name' => $block['name'],
                'permissions' => array_map(fn (string $key, string $label) => ['key' => $key, 'label' => $label], array_keys($block['permissions']), $block['permissions']),
            ];
        }

        return response()->json(['data' => $blocks]);
    }

    public function show(CompanyContext $context, string $role): CompanyRoleResource
    {
        $this->authorize('users.view');

        return new CompanyRoleResource($this->find($context, $role)->loadCount('memberships as users_count'));
    }

    public function store(Request $request, CompanyContext $context, SaveRoleAction $save): JsonResponse
    {
        $this->authorize('users.manage');

        return (new CompanyRoleResource($save->execute($context->company(), $request->user(), new Role, $this->validated($request, true))))
            ->response()->setStatusCode(201);
    }

    public function update(Request $request, CompanyContext $context, SaveRoleAction $save, string $role): CompanyRoleResource
    {
        $this->authorize('users.manage');

        return new CompanyRoleResource($save->execute($context->company(), $request->user(), $this->find($context, $role), $this->validated($request, false)));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'min:2', 'max:80'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'isActive' => ['sometimes', 'boolean'],
            'permissions' => [$creating ? 'sometimes' : 'sometimes', 'array', 'max:300'],
            'permissions.*' => ['string', 'max:80', 'distinct'],
        ]);
    }

    private function find(CompanyContext $context, string $publicId): Role
    {
        return Role::query()->where('company_id', $context->company()->id)->where('public_id', $publicId)->firstOrFail();
    }
}
