<?php

namespace App\Domains\Identity\DTOs;

use App\Models\User;

final readonly class AuthenticatedUserData
{
    public function __construct(
        public string $publicId,
        public string $name,
        public string $email,
    ) {}

    public static function fromUser(User $user): self
    {
        return new self($user->public_id, $user->name, $user->email);
    }
}
