<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Enums\CompanyRole;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CompanyRoleController extends Controller
{
    public function __invoke(Request $request, CompanyContext $context): JsonResponse
    {
        $company = $context->company();
        $this->authorize('update', $company);

        $actorRole = $company->memberships()
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->value('role');
        $actorRole = $actorRole instanceof CompanyRole ? $actorRole : CompanyRole::from($actorRole);

        return response()->json([
            'data' => array_map(static fn (CompanyRole $role): array => [
                'code' => $role->value,
                'label' => $role->label(),
                'description' => $role->description(),
                'canAssign' => $actorRole->canManage($role),
            ], CompanyRole::cases()),
        ]);
    }
}
