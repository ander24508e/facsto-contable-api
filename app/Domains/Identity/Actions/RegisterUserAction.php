<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

final class RegisterUserAction
{
    public function execute(string $name, string $email, string $password): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => mb_strtolower($email),
            'password' => Hash::make($password),
            'status' => UserStatus::ACTIVE,
        ]);
    }
}
