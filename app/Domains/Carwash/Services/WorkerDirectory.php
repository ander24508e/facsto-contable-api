<?php

namespace App\Domains\Carwash\Services;

use App\Domains\Companies\Access\CompanyAccess;
use App\Domains\Companies\Models\Company;
use App\Models\User;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Collection;

/**
 * Trabajadores de la Lavadora: los usuarios activos de la empresa cuyo rol tiene «Puede ser asignado como
 * trabajador» (carwash.assignable). Entran con su correo y contraseña; los crea Usuarios y roles, no la Lavadora.
 */
final class WorkerDirectory
{
    public const PERMISSION = 'carwash.assignable';

    public function __construct(private readonly CompanyAccess $access) {}

    /** @return Collection<int, User> */
    public function list(Company $company): Collection
    {
        return User::query()->whereIn('id', $this->access->usersGranted($company, self::PERMISSION))->orderBy('name')->get();
    }

    /** The worker of an order; the one it already has is kept even if it is no longer assignable. */
    public function resolve(Company $company, ?string $publicId, ?int $current = null): ?User
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }
        $user = User::query()->where('public_id', $publicId)->first();
        if ($user && ($user->id === $current || in_array($user->id, $this->access->usersGranted($company, self::PERMISSION), true))) {
            return $user;
        }

        throw new ApiException(ApiErrorCode::CARWASH_WASHER_NOT_FOUND, 'El trabajador no existe o ya no puede ser asignado.', 422);
    }
}
