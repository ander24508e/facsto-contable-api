<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_changes_password_with_the_current_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/v1/me/password', [
            'currentPassword' => 'password',
            'password' => 'NuevaClave2026',
            'passwordConfirmation' => 'NuevaClave2026',
        ])->assertOk()->assertJsonPath('data.message', 'Contraseña actualizada.');

        $this->assertTrue(Hash::check('NuevaClave2026', $user->refresh()->password));
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/v1/me/password', [
            'currentPassword' => 'incorrecta',
            'password' => 'NuevaClave2026',
            'passwordConfirmation' => 'NuevaClave2026',
        ])->assertUnprocessable()
            ->assertJsonPath('error.details.errors.currentPassword.0', 'La contraseña actual no es correcta.');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_new_password_must_be_confirmed_strong_and_different(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/v1/me/password', [
            'currentPassword' => 'password',
            'password' => 'NuevaClave2026',
            'passwordConfirmation' => 'OtraClave2026',
        ])->assertUnprocessable()->assertJsonPath('error.details.errors.password.0', 'Las contraseñas no coinciden.');

        $this->actingAs($user)->putJson('/api/v1/me/password', [
            'currentPassword' => 'password',
            'password' => 'corta1',
            'passwordConfirmation' => 'corta1',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password'], 'error.details.errors');
    }
}
