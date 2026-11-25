<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ThemePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_chosen_mode_is_kept_in_the_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.theme', 'light');
        $this->actingAs($user)->putJson('/api/v1/me/theme', ['theme' => 'dark'])->assertOk()->assertJsonPath('data.theme', 'dark');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'theme' => 'dark']);
        $this->actingAs($user)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.theme', 'dark');
    }

    public function test_only_light_or_dark_are_accepted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/v1/me/theme', ['theme' => 'blue'])->assertUnprocessable();
        // actingAs keeps the user for the next requests of the test: sign out to check the guest.
        $this->app['auth']->forgetGuards();
        $this->putJson('/api/v1/me/theme', ['theme' => 'dark'])->assertUnauthorized();
    }
}
