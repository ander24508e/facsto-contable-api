<?php

namespace Tests\Feature\Catalog;

use App\Domains\Catalog\Models\Item;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class ItemImageTest extends CatalogTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    private function pngFile(string $name): UploadedFile
    {
        // Real 1x1 PNG: the container has no GD to generate fake images.
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
    }

    public function test_an_item_photo_is_uploaded_replaced_read_and_deleted_privately(): void
    {
        $user = User::factory()->create();
        $this->createCompany($user, '1799999999001');
        $item = $this->createItemThroughApi($user);
        $url = '/api/v1/items/'.$item['publicId'].'/image';

        $this->post($url, ['image' => $this->pngFile('foto.png')], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.hasImage', true);
        $first = Item::query()->where('public_id', $item['publicId'])->value('image_path');
        Storage::disk('private')->assertExists($first);

        $this->post($url, ['image' => $this->pngFile('nueva.png')], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('private')->assertMissing($first);
        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $second = Item::query()->where('public_id', $item['publicId'])->value('image_path');
        $this->deleteJson($url)->assertOk()->assertJsonPath('data.hasImage', false);
        Storage::disk('private')->assertMissing($second);
        $this->get($url, ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_the_photo_must_be_an_image_and_stays_inside_its_company(): void
    {
        $user = User::factory()->create();
        $this->createCompany($user, '1799999999001');
        $item = $this->createItemThroughApi($user);
        $url = '/api/v1/items/'.$item['publicId'].'/image';

        $this->post($url, ['image' => UploadedFile::fake()->create('lista.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['image'], 'error.details.errors');
        $this->post($url, ['image' => $this->pngFile('foto.png')], ['Accept' => 'application/json'])->assertOk();

        $stranger = User::factory()->create();
        $this->createCompany($stranger, '1790016919001');
        $this->actingAs($stranger)->get($url, ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($stranger)->deleteJson($url)->assertForbidden();
    }
}
