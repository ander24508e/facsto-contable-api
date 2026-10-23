<?php

namespace Database\Seeders;

use App\Domains\Identity\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * BORRADOR TEMPORAL: ejecutar manualmente y eliminar cuando termine el acceso temporal.
 * No registrar en DatabaseSeeder ni en DevelopmentSeeder.
 */
class TemporaryDraftLoginSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'adeb2737@gmail.com'],
            [
                'name' => 'Acceso temporal de desarrollo',
                'password' => Hash::make('Admin123'),
                'status' => UserStatus::ACTIVE,
            ],
        );
    }
}
