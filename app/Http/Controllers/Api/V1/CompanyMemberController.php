<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Actions\AddCompanyMemberAction;
use App\Domains\Companies\Actions\CreateCompanyUserAction;
use App\Domains\Companies\Actions\ReactivateCompanyMemberAction;
use App\Domains\Companies\Actions\RemoveCompanyMemberAction;
use App\Domains\Companies\Actions\UpdateCompanyMemberRolesAction;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\CompanyMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\AddCompanyMemberRequest;
use App\Http\Requests\Companies\CreateCompanyUserRequest;
use App\Http\Requests\Companies\IndexCompanyMembersRequest;
use App\Http\Requests\Companies\UpdateCompanyMemberRolesRequest;
use App\Http\Resources\Api\V1\CompanyMembershipResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Usuarios de la empresa: se ven con «users.view» y se crean, cambian de roles o desactivan con «users.manage». */
final class CompanyMemberController extends Controller
{
    public function index(IndexCompanyMembersRequest $request, CompanyContext $context): AnonymousResourceCollection
    {
        $company = $context->company();
        $this->authorize('users.view');

        $filters = $request->validated();
        $memberships = CompanyMembership::query()
            ->with(['user', 'roles'])
            ->where('company_id', $company->id)
            ->when(filled($filters['search'] ?? null), function (Builder $query) use ($filters): void {
                $search = '%'.mb_strtolower(trim($filters['search'])).'%';
                $query->whereHas('user', fn (Builder $users) => $users
                    ->whereRaw('LOWER(name) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$search]));
            })
            ->when(filled($filters['role'] ?? null), fn (Builder $query) => $query->whereHas('roles', fn (Builder $roles) => $roles->where('company_roles.public_id', $filters['role'])))
            ->when(($filters['status'] ?? 'ALL') !== 'ALL', fn (Builder $query) => $query->where('is_active', ($filters['status'] ?? 'ALL') === 'ACTIVE'))
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return CompanyMembershipResource::collection($memberships);
    }

    public function createUser(CreateCompanyUserRequest $request, CompanyContext $context, CreateCompanyUserAction $create): CompanyMembershipResource
    {
        $this->authorize('users.manage');
        $data = $request->validated();
        $roles = $data['rolePublicIds'];
        unset($data['rolePublicIds']);

        return new CompanyMembershipResource($create->execute($context->company(), $request->user(), $roles, $data));
    }

    public function store(AddCompanyMemberRequest $request, CompanyContext $context, AddCompanyMemberAction $add): CompanyMembershipResource
    {
        $this->authorize('users.manage');
        $user = User::query()->where('public_id', $request->validated('userPublicId'))->firstOrFail();
        $membership = $add->execute($context->company(), $request->user(), $user, $request->validated('rolePublicIds'));

        return new CompanyMembershipResource($membership->load('user', 'roles'));
    }

    public function destroy(Request $request, CompanyContext $context, string $userPublicId, RemoveCompanyMemberAction $remove): CompanyMembershipResource
    {
        $company = $context->company();
        $this->authorize('users.manage');
        $membership = $this->membership($company, $userPublicId);

        $remove->execute($company, $membership, $request->user());

        return new CompanyMembershipResource($membership->refresh()->load('user', 'roles'));
    }

    public function reactivate(Request $request, CompanyContext $context, string $userPublicId, ReactivateCompanyMemberAction $reactivate): CompanyMembershipResource
    {
        $company = $context->company();
        $this->authorize('users.manage');
        $membership = $this->membership($company, $userPublicId);

        return new CompanyMembershipResource($reactivate->execute($company, $membership, $request->user())->load('user', 'roles'));
    }

    public function updateRoles(UpdateCompanyMemberRolesRequest $request, CompanyContext $context, string $userPublicId, UpdateCompanyMemberRolesAction $update): CompanyMembershipResource
    {
        $company = $context->company();
        $this->authorize('users.manage');
        $membership = $this->membership($company, $userPublicId);

        return new CompanyMembershipResource($update->execute($company, $membership, $request->user(), $request->validated('rolePublicIds'))->load('user', 'roles'));
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
