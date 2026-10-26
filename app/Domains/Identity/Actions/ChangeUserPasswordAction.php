<?php

namespace App\Domains\Identity\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * AuthenticateSession compares the stored password hash on every stateful
 * request, so other browser sessions are signed out once the hash changes.
 */
final class ChangeUserPasswordAction
{
    public function execute(User $user, #[\SensitiveParameter] string $password): User
    {
        $user->forceFill([
            'password' => Hash::make($password),
            'remember_token' => null,
        ])->save();

        return $user;
    }
}
