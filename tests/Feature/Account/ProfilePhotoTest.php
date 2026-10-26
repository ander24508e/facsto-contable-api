<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    private function pngFile(string $name): UploadedFile
    {
        // PNG real de 1x1 px: el contenedor no incluye GD para generar imágenes falsas.
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
    }

    public function test_user_uploads_replaces_reads_and_deletes_a_private_photo(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/api/v1/me/photo', ['photo' => $this->pngFile('perfil.png')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.hasProfilePhoto', true);
        $firstPath = $user->refresh()->profile_photo_path;
        $this->assertStringStartsWith('users/'.$user->public_id.'/profile/', $firstPath);
        Storage::disk('private')->assertExists($firstPath);

        $this->actingAs($user)->post('/api/v1/me/photo', ['photo' => $this->pngFile('nueva.jpg')], ['Accept' => 'application/json'])
            ->assertOk();
        Storage::disk('private')->assertMissing($firstPath);

        $this->actingAs($user)->get('/api/v1/me/photo')->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $secondPath = $user->refresh()->profile_photo_path;
        $this->actingAs($user)->deleteJson('/api/v1/me/photo')->assertOk()->assertJsonPath('data.hasProfilePhoto', false);
        Storage::disk('private')->assertMissing($secondPath);
        $this->actingAs($user)->get('/api/v1/me/photo', ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_photo_must_be_an_allowed_image(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/api/v1/me/photo', ['photo' => UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['photo'], 'error.details.errors');
    }

    public function test_users_only_read_their_own_photo(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->post('/api/v1/me/photo', ['photo' => $this->pngFile('perfil.png')], ['Accept' => 'application/json'])->assertOk();

        $other = User::factory()->create();
        $this->actingAs($other)->get('/api/v1/me/photo', ['Accept' => 'application/json'])->assertNotFound();
    }
}
