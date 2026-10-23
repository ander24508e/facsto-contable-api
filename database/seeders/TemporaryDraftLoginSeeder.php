<?php

namespace Database\Seeders;

use App\Domains\Identity\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;
use LogicException;

/**
 * BORRADOR TEMPORAL: ejecutar manualmente y eliminar cuando termine el acceso temporal.
 * No registrar en DatabaseSeeder ni en DevelopmentSeeder.
 */
class TemporaryDraftLoginSeeder extends Seeder
{
    public function run(): void
    {
        if (! App::environment(['local', 'testing'])) {
            throw new LogicException('TemporaryDraftLoginSeeder sólo puede ejecutarse en local o testing.');
        }

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
