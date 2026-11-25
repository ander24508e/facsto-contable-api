<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\Fiscal\Actions\ProvisionDefaultFiscalSettingsAction;
use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Models\CompanyMembership;
use App\Domains\Companies\Services\CompanyRoles;
use App\Domains\Identity\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

/**
 * One deployment, one client: leaves the instance's company and Condore's support administrator ready from the
 * environment (config/instance.php), so nobody has to open a container. Idempotent: it runs on every deploy, keeps
 * the support account active, administrator and with the password Dokploy holds. The client then creates its own
 * users (its own email and password) from Usuarios; one email still belongs to one company.
 */
final class EnsureInstanceAdministratorAction
{
    private const SLUG_PATTERN = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/';

    private const MIN_PASSWORD_LENGTH = 12;

    public function __construct(
        private readonly CreateCompanyAction $createCompany,
        private readonly ProvisionDefaultFiscalSettingsAction $provisionSettings,
        private readonly CompanyRoles $roles,
    ) {}

    public function execute(string $slug, string $name, string $email, string $password): Company
    {
        $slug = mb_strtolower(trim($slug));
        $email = mb_strtolower(trim($email));

        if (! preg_match(self::SLUG_PATTERN, $slug)) {
            throw new InvalidArgumentException('INSTANCE_COMPANY_SLUG sólo admite minúsculas, números y guiones (máximo 63).');
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('SUPPORT_ADMIN_EMAIL no es un correo válido.');
        }
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new InvalidArgumentException('SUPPORT_ADMIN_PASSWORD debe tener al menos '.self::MIN_PASSWORD_LENGTH.' caracteres.');
        }

        return DB::transaction(function () use ($slug, $name, $email, $password): Company {
            $user = $this->support($name, $email, $password);
            $company = Company::query()->where('slug', $slug)->first();

            $elsewhere = CompanyMembership::query()->where('user_id', $user->id)
                ->when($company, fn ($memberships) => $memberships->where('company_id', '<>', $company->id))
                ->exists();
            if ($elsewhere) {
                throw new InvalidArgumentException("El correo {$email} ya pertenece a otra empresa de esta instalación.");
            }

            if (! $company) {
                $company = $this->createCompany->execute($user, ['slug' => $slug]);
                $this->provisionSettings->execute($company);

                return $company->refresh();
            }

            $membership = $company->memberships()->firstOrCreate(['user_id' => $user->id], ['is_active' => true, 'joined_at' => now()]);
            if (! $membership->is_active) {
                $membership->forceFill(['is_active' => true])->save();
            }
            $this->roles->makeAdministrator($membership);

            return $company->refresh();
        });
    }

    private function support(string $name, string $email, string $password): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill(['name' => trim($name) ?: 'Soporte Condore', 'status' => UserStatus::ACTIVE]);
        if (! $user->exists || ! Hash::check($password, (string) $user->password)) {
            $user->forceFill(['password' => Hash::make($password)]);
        }
        $user->save();

        return $user;
    }
}
