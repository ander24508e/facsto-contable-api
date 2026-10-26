<?php

namespace App\Domains\Identity\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateUserProfileAction
{
    /** @param array{name?: string, email?: string, phone?: ?string} $attributes */
    public function execute(User $user, array $attributes): User
    {
        if (array_key_exists('email', $attributes)) {
            $attributes['email'] = mb_strtolower($attributes['email']);
        }

        return DB::transaction(function () use ($user, $attributes): User {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $user->fill($attributes)->save();

            return $user;
        });
    }
}
