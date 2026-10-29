<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UpdateProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_updates_name_and_phone_without_password(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com']);

        $this->actingAs($user)->patchJson('/api/v1/me', ['name' => 'Ana Endara', 'phone' => '0981234546'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Ana Endara')
            ->assertJsonPath('data.phone', '0981234546')
            ->assertJsonPath('data.email', 'ana@example.com')
            ->assertJsonPath('data.hasProfilePhoto', false);
    }

    public function test_changing_email_requires_the_current_password(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com']);

        $this->actingAs($user)->patchJson('/api/v1/me', ['email' => 'nueva@example.com'])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.errors.currentPassword.0', 'Confirma tu contraseña actual para cambiar el correo.');

        $this->actingAs($user)->patchJson('/api/v1/me', ['email' => 'nueva@example.com', 'currentPassword' => 'incorrecta'])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.errors.currentPassword.0', 'La contraseña actual no es correcta.');

        $this->assertSame('ana@example.com', $user->refresh()->email);
    }

    public function test_email_changes_with_the_current_password_and_is_normalized(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com']);

        $this->actingAs($user)->patchJson('/api/v1/me', ['email' => 'Nueva@Example.com', 'currentPassword' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.email', 'nueva@example.com');
    }

    public function test_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'tomado@example.com']);
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/v1/me', ['email' => 'tomado@example.com', 'currentPassword' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email'], 'error.details.errors');
    }

    public function test_guest_cannot_update_a_profile(): void
    {
        $this->patchJson('/api/v1/me', ['name' => 'Intruso'])->assertUnauthorized();
    }
}
