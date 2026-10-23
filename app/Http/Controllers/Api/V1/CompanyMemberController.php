<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Actions\AddCompanyMemberAction;
use App\Domains\Companies\Actions\ReactivateCompanyMemberAction;
use App\Domains\Companies\Actions\RemoveCompanyMemberAction;
use App\Domains\Companies\Actions\UpdateCompanyMemberRoleAction;
use App\Domains\Companies\Enums\CompanyRole;
use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\CompanyMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\AddCompanyMemberRequest;
use App\Http\Requests\Companies\UpdateCompanyMemberRoleRequest;
use App\Http\Resources\Api\V1\CompanyMembershipResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CompanyMemberController extends Controller
{
    public function store(AddCompanyMemberRequest $request, Company $company, AddCompanyMemberAction $add): CompanyMembershipResource
    {
        $this->authorize('update', $company);
        $user = User::query()->where('public_id', $request->validated('userPublicId'))->firstOrFail();
        $membership = $add->execute($company, $request->user(), $user, CompanyRole::from($request->validated('role')));

        return new CompanyMembershipResource($membership->load('user'));
    }

    public function destroy(Request $request, Company $company, string $userPublicId, RemoveCompanyMemberAction $remove): JsonResponse
    {
        $this->authorize('update', $company);
        $user = User::query()->where('public_id', $userPublicId)->firstOrFail();
        $membership = CompanyMembership::query()
            ->where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $remove->execute($company, $membership, $request->user());

        return response()->json(['data' => ['isActive' => false]]);
    }

    public function reactivate(Request $request, Company $company, string $userPublicId, ReactivateCompanyMemberAction $reactivate): CompanyMembershipResource
    {
        $this->authorize('update', $company);
        $membership = $this->membership($company, $userPublicId);

        return new CompanyMembershipResource($reactivate->execute($company, $membership, $request->user())->load('user'));
    }

    public function updateRole(UpdateCompanyMemberRoleRequest $request, Company $company, string $userPublicId, UpdateCompanyMemberRoleAction $update): CompanyMembershipResource
    {
        $this->authorize('update', $company);
        $membership = $this->membership($company, $userPublicId);

        return new CompanyMembershipResource($update->execute(
            $company,
            $membership,
            $request->user(),
            CompanyRole::from($request->validated('role')),
        )->load('user'));
    }

    private function membership(Company $company, string $userPublicId): CompanyMembership
    {
        $user = User::query()->where('public_id', $userPublicId)->firstOrFail();

        return CompanyMembership::query()
            ->where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->firstOrFail();
    }
}
