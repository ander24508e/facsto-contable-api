<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\Enums\CompanyRole;
use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\CompanyMembership;
use App\Domains\Identity\Enums\UserStatus;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class CreateCompanyUserAction
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param array{name: string, email: string, phone?: ?string, password: string} $data */
    public function execute(Company $company, User $actor, CompanyRole $role, array $data): CompanyMembership
    {
        return DB::transaction(function () use ($company, $actor, $role, $data): CompanyMembership {
            $lockedCompany = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $actorRole = $lockedCompany->memberships()
                ->where('user_id', $actor->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->value('role');
            $actorRole = $actorRole instanceof CompanyRole ? $actorRole : ($actorRole ? CompanyRole::from($actorRole) : null);

            if (! $actorRole || ! $actorRole->canManage($role)) {
                throw new ApiException(ApiErrorCode::COMPANY_ACCESS_DENIED, 'No puedes crear un usuario con ese rol.', 403);
            }

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
                'role' => $role,
                'is_active' => true,
                'joined_at' => now(),
            ]);

            $this->audit->record($actor, $lockedCompany, 'company.user_created', $user, null, [
                'role' => $role->value,
                'isActive' => true,
            ]);

            return $membership->load('user');
        });
    }
}
