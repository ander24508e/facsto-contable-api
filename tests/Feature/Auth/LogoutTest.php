<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_log_out_and_invalidate_the_session(): void
    {
        $user = User::factory()->create();

        $this->withoutMiddleware(PreventRequestForgery::class)
            ->actingAs($user)
            ->postJson('/logout')
            ->assertOk()
            ->assertJsonPath('data.message', 'Sesión cerrada.');

        $this->assertGuest();
    }
}
