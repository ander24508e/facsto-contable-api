<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\CompanyMembership;
use App\Domains\Companies\Services\CompanyRoles;
use App\Domains\Identity\Enums\UserStatus;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/** Usuario nuevo de la empresa: entra con su correo y contraseña y lleva uno o varios roles. */
final class CreateCompanyUserAction
{
    public function __construct(private readonly AuditRecorder $audit, private readonly CompanyRoles $roles) {}

    /**
     * @param  list<string>  $rolePublicIds
     * @param  array{name: string, email: string, phone?: ?string, password: string}  $data
     */
    public function execute(Company $company, User $actor, array $rolePublicIds, array $data): CompanyMembership
    {
        return DB::transaction(function () use ($company, $actor, $rolePublicIds, $data): CompanyMembership {
            $lockedCompany = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $roles = $this->roles->resolve($lockedCompany, $rolePublicIds);

            $email = mb_strtolower(trim($data['email']));
            if (User::query()->where('email', $email)->exists()) {
                throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'El correo ya está registrado en FACSTO.', 422, ['email' => ['El correo ya está registrado en FACSTO.']]);
            }

            $user = User::query()->create([
                'name' => trim($data['name']),
                'email' => $email,
                'phone' => filled($data['phone'] ?? null) ? trim((string) $data['phone']) : null,
                'password' => $data['password'],
                'status' => UserStatus::ACTIVE,
            ]);

            $membership = $lockedCompany->memberships()->create([
                'user_id' => $user->id,
                'is_active' => true,
                'joined_at' => now(),
            ]);
            $this->roles->assign($lockedCompany, $actor, $membership, $roles);

            $this->audit->record($actor, $lockedCompany, 'company.user_created', $user, null, [
                'roles' => $roles->pluck('name')->all(),
                'isActive' => true,
            ]);

            return $membership->load('user', 'roles');
        });
    }
}
