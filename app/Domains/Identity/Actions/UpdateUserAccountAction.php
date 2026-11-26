<?php

namespace App\Domains\Identity\Actions;

use App\Models\User;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class UpdateUserAccountAction
{
    /** @param array{name?: string, email?: string, phone?: ?string} $attributes */
    public function execute(User $user, array $attributes, ?string $newPassword = null): User
    {
        if (array_key_exists('email', $attributes)) {
            $attributes['email'] = mb_strtolower($attributes['email']);
        }

        return DB::transaction(function () use ($user, $attributes, $newPassword): User {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $user->fill($attributes);
            if ($user->isInstanceSupport() && ($newPassword !== null || $user->isDirty(['name', 'email']))) {
                throw self::supportManagedByInstallation();
            }

            if ($newPassword !== null) {
                $user->forceFill([
                    'password' => Hash::make($newPassword),
                    'remember_token' => null,
                ]);
            }

            $user->save();

            return $user;
        });
    }

    /** The support account's name, email and password come only from the installation (Dokploy). */
    public static function supportManagedByInstallation(): ApiException
    {
        return new ApiException(ApiErrorCode::SUPPORT_ACCOUNT_LOCKED, 'El nombre, el correo y la contraseña de la cuenta de soporte se administran desde la instalación.', 403);
    }
}
