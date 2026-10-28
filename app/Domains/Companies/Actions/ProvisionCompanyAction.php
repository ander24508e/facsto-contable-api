<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\DTOs\ProvisionAdministratorData;
use App\Domains\Companies\DTOs\ProvisionCompanyData;
use App\Domains\Companies\Enums\CompanyRole;
use App\Domains\Companies\Fiscal\Actions\ProvisionDefaultFiscalSettingsAction;
use App\Domains\Companies\Models\Company;
use App\Domains\Identity\Enums\UserStatus;
use App\Models\User;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Activates one subscribed company: the company with its slug, the SRI test environment, the
 * Condore operator as OWNER and the client's administrator as ADMIN. No fiscal data is pre-filled:
 * the client enters RUC, data, signature and emission structure in the welcome flow.
 */
final class ProvisionCompanyAction
{
    private const SLUG_PATTERN = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/';

    public function __construct(
        private readonly CreateCompanyAction $createCompany,
        private readonly ProvisionDefaultFiscalSettingsAction $provisionSettings,
    ) {}

    /** @return array{company: Company, credentials: array<string, string>} Temporary passwords by email. */
    public function execute(ProvisionCompanyData $data): array
    {
        $slug = mb_strtolower(trim($data->slug));
        if (! preg_match(self::SLUG_PATTERN, $slug)) {
            throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'El identificador sólo admite minúsculas, números y guiones (máximo 63).', 422);
        }

        return DB::transaction(function () use ($data, $slug): array {
            if (Company::query()->where('slug', $slug)->exists()) {
                throw new ApiException(ApiErrorCode::COMPANY_SLUG_TAKEN, 'Ya existe una empresa con ese identificador.', 409);
            }

            $credentials = [];
            $operator = $this->createUser($data->operator, $credentials);
            $company = $this->createCompany->execute($operator, ['slug' => $slug]);

            if ($data->clientAdmin) {
                $company->memberships()->create([
                    'user_id' => $this->createUser($data->clientAdmin, $credentials)->id,
                    'role' => CompanyRole::ADMIN,
                    'is_active' => true,
                    'joined_at' => now(),
                ]);
            }

            $this->provisionSettings->execute($company);

            return ['company' => $company->refresh(), 'credentials' => $credentials];
        });
    }

    /** @param array<string, string> $credentials */
    private function createUser(ProvisionAdministratorData $administrator, array &$credentials): User
    {
        $email = mb_strtolower(trim($administrator->email));
        if (User::query()->where('email', $email)->exists()) {
            throw new ApiException(ApiErrorCode::USER_BELONGS_TO_ANOTHER_COMPANY, "El correo {$email} ya tiene una cuenta FACSTO.", 409);
        }

        $password = Str::password(16, symbols: false);
        $credentials[$email] = $password;

        return User::query()->create([
            'name' => trim($administrator->name),
            'email' => $email,
            'password' => Hash::make($password),
            'status' => UserStatus::ACTIVE,
        ]);
    }
}
