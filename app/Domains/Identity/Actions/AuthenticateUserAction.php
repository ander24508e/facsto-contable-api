<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

final class AuthenticateUserAction
{
    public function execute(string $email, string $password, bool $remember = false): ?User
    {
        $user = User::query()->where('email', mb_strtolower($email))->first();

        if (! $user || $user->status !== UserStatus::ACTIVE || ! Hash::check($password, $user->password)) {
            return null;
        }

        Auth::guard('web')->login($user, $remember);
        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }
}
