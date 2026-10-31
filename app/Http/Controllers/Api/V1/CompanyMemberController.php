<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Actions\AddCompanyMemberAction;
use App\Domains\Companies\Actions\CreateCompanyUserAction;
use App\Domains\Companies\Actions\ReactivateCompanyMemberAction;
use App\Domains\Companies\Actions\RemoveCompanyMemberAction;
use App\Domains\Companies\Actions\UpdateCompanyMemberRoleAction;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Enums\CompanyRole;
use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\CompanyMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\AddCompanyMemberRequest;
use App\Http\Requests\Companies\CreateCompanyUserRequest;
use App\Http\Requests\Companies\IndexCompanyMembersRequest;
use App\Http\Requests\Companies\UpdateCompanyMemberRoleRequest;
use App\Http\Resources\Api\V1\CompanyMembershipResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CompanyMemberController extends Controller
{
    public function index(IndexCompanyMembersRequest $request, CompanyContext $context): AnonymousResourceCollection
    {
        $company = $context->company();
        $this->authorize('update', $company);

        $filters = $request->validated();
        $memberships = CompanyMembership::query()
            ->with('user')
            ->where('company_id', $company->id)
            ->when(filled($filters['search'] ?? null), function (Builder $query) use ($filters): void {
                $search = '%'.mb_strtolower(trim($filters['search'])).'%';
                $query->whereHas('user', fn (Builder $users) => $users
                    ->whereRaw('LOWER(name) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$search]));
            })
            ->when(filled($filters['role'] ?? null), fn (Builder $query) => $query->where('role', $filters['role']))
            ->when(($filters['status'] ?? 'ALL') !== 'ALL', fn (Builder $query) => $query->where('is_active', ($filters['status'] ?? 'ALL') === 'ACTIVE'))
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return CompanyMembershipResource::collection($memberships);
    }

    public function createUser(CreateCompanyUserRequest $request, CompanyContext $context, CreateCompanyUserAction $create): CompanyMembershipResource
    {
        $company = $context->company();
        $this->authorize('update', $company);
        $data = $request->validated();
        $role = CompanyRole::from($data['role']);
        unset($data['role']);

        return new CompanyMembershipResource($create->execute($company, $request->user(), $role, $data));
    }

    public function store(AddCompanyMemberRequest $request, CompanyContext $context, AddCompanyMemberAction $add): CompanyMembershipResource
    {
        $company = $context->company();
        $this->authorize('update', $company);
        $user = User::query()->where('public_id', $request->validated('userPublicId'))->firstOrFail();
        $membership = $add->execute($company, $request->user(), $user, CompanyRole::from($request->validated('role')));

        return new CompanyMembershipResource($membership->load('user'));
    }

    public function destroy(Request $request, CompanyContext $context, string $userPublicId, RemoveCompanyMemberAction $remove): CompanyMembershipResource
    {
        $company = $context->company();
        $this->authorize('update', $company);
        $user = User::query()->where('public_id', $userPublicId)->firstOrFail();
        $membership = CompanyMembership::query()
            ->where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $remove->execute($company, $membership, $request->user());

        return new CompanyMembershipResource($membership->refresh()->load('user'));
    }

    public function reactivate(Request $request, CompanyContext $context, string $userPublicId, ReactivateCompanyMemberAction $reactivate): CompanyMembershipResource
    {
        $company = $context->company();
        $this->authorize('update', $company);
        $membership = $this->membership($company, $userPublicId);

        return new CompanyMembershipResource($reactivate->execute($company, $membership, $request->user())->load('user'));
    }

    public function updateRole(UpdateCompanyMemberRoleRequest $request, CompanyContext $context, string $userPublicId, UpdateCompanyMemberRoleAction $update): CompanyMembershipResource
    {
        $company = $context->company();
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
