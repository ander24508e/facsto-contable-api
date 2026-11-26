<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\CompanyMembership;
use App\Domains\Companies\Services\CompanyRoles;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use App\Shared\Realtime\Realtime;
use App\Shared\Realtime\VersionGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateCompanyUserAction
{
    public function __construct(private readonly CompanyRoles $roles, private readonly AuditRecorder $audit, private readonly VersionGuard $versions, private readonly Realtime $realtime) {}

    /** @param array{name: string, email: string, phone?: ?string, version: string} $data */
    public function execute(Company $company, CompanyMembership $membership, User $actor, array $data): CompanyMembership
    {
        return DB::transaction(function () use ($company, $membership, $actor, $data): CompanyMembership {
            $lockedCompany = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $member = CompanyMembership::query()->where('company_id', $lockedCompany->id)->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            $this->roles->assertCanManage($lockedCompany, $actor, $member);
            $user = User::query()->whereKey($member->user_id)->lockForUpdate()->firstOrFail();
            if (CompanyMembership::query()->where('user_id', $user->id)->where('company_id', '!=', $company->id)->exists()) {
                throw new ApiException(ApiErrorCode::USER_BELONGS_TO_ANOTHER_COMPANY, 'Esta cuenta tiene historial en otra empresa y no puede editarse desde aquí.', 409);
            }
            $this->versions->assertCurrent($user, $data['version']);
            $email = mb_strtolower(trim($data['email']));
            if (User::query()->whereKeyNot($user->id)->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                throw ValidationException::withMessages(['email' => 'El correo ya está registrado.']);
            }
            $before = $user->only(['name', 'email', 'phone']);
            if ($email !== $user->email) {
                $user->email_verified_at = null;
            }
            $user->fill(['name' => trim($data['name']), 'email' => $email, 'phone' => filled($data['phone'] ?? null) ? trim($data['phone']) : null])->save();
            $this->audit->record($actor, $lockedCompany, 'company.user_updated', $user, $before, $user->only(['name', 'email', 'phone']));
            $this->realtime->model($lockedCompany, 'users', $user);
            $this->realtime->user($user, 'access');

            return $member->load('user', 'roles');
        });
    }
}
