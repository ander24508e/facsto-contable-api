<?php

namespace App\Domains\Identity\Actions;

use App\Models\User;
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
}
