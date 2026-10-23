<?php

namespace Tests\Feature\Auth;

use App\Domains\Identity\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_log_in_with_a_session_cookie(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('SecurePass123'),
            'status' => UserStatus::ACTIVE,
        ]);

        $this->withoutMiddleware(PreventRequestForgery::class)
            ->postJson('/login', ['email' => $user->email, 'password' => 'SecurePass123'])
            ->assertOk()
            ->assertJsonPath('data.publicId', $user->public_id);

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', $user->email);
    }

    public function test_disabled_users_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'email' => 'disabled@example.com',
            'password' => Hash::make('SecurePass123'),
            'status' => UserStatus::DISABLED,
        ]);

        $this->withoutMiddleware(PreventRequestForgery::class)
            ->postJson('/login', ['email' => $user->email, 'password' => 'SecurePass123'])
            ->assertUnprocessable();

        $this->assertGuest();
    }
}
