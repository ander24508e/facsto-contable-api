<?php

namespace Tests\Feature\Carwash;

use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Carwash\Models\WorkOrderEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Evidencias de la recepción: fotos, videos y firma en el disco privado, acta en PDF, «En reclamo» y vencimiento. */
final class CarwashEvidenceTest extends CarwashTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    private function png(string $name = 'foto.png'): UploadedFile
    {
        // PNG real de 1x1 px: el contenedor no incluye GD para generar imágenes falsas.
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
    }

    public function test_the_inspector_uploads_photos_and_the_signature_and_the_report_lists_them(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $order = $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($setup))->assertCreated()->json('data.publicId');
        $inspector = $this->memberAs($company, ['carwash.inspect']);
        $viewer = $this->memberAs($company, ['carwash.view']);
        $upload = fn ($user, string $kind, UploadedFile $file) => $this->actingAs($user)->post(self::API."/work-orders/$order/evidences", ['kind' => $kind, 'file' => $file, 'takenAt' => now()->toIso8601String()], ['Accept' => 'application/json']);

        $upload($viewer, 'PHOTO', $this->png())->assertForbidden();
        $photo = $upload($inspector, 'PHOTO', $this->png())->assertCreated()
            ->assertJsonPath('data.kind', 'PHOTO')->assertJsonPath('data.sha256', hash('sha256', (string) $this->png()->getContent()))->json('data');
        $upload($inspector, 'VIDEO', $this->png('video.mp4'))->assertUnprocessable();
        $upload($inspector, 'SIGNATURE', $this->png('firma.png'))->assertCreated();
        $upload($inspector, 'SIGNATURE', $this->png('firma.png'))->assertConflict();

        $evidence = WorkOrderEvidence::query()->where('public_id', $photo['publicId'])->sole();
        Storage::disk('private')->assertExists($evidence->path);
        $this->assertSame(now()->addDays(90)->toDateString(), $evidence->expires_at->toDateString());

        $this->actingAs($inspector)->getJson(self::API."/work-orders/$order/evidences")->assertOk()->assertJsonCount(2, 'data.items')->assertJsonPath('data.inClaim', false);
        $this->actingAs($inspector)->get(self::API."/evidences/{$photo['publicId']}/file")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($viewer)->getJson(self::API."/work-orders/$order/evidences")->assertForbidden();
        // The report is for «Ver evidencias» (the owner has every permission).
        $this->actingAs($inspector)->get(self::API."/work-orders/$order/reception-report")->assertForbidden();
        $report = $this->actingAs($owner)->get(self::API."/work-orders/$order/reception-report")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $report->getContent());
    }

    public function test_expired_files_are_purged_unless_the_order_is_in_claim(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $first = $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($setup))->assertCreated()->json('data.publicId');
        $second = $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($this->carwashSetup($company, 'PBB2222')))->assertCreated()->json('data.publicId');
        foreach ([$first, $second] as $order) {
            $this->actingAs($owner)->post(self::API."/work-orders/$order/evidences", ['kind' => 'PHOTO', 'file' => $this->png()], ['Accept' => 'application/json'])->assertCreated();
        }
        $this->actingAs($owner)->patchJson(self::API."/work-orders/$second/claim", ['inClaim' => true])->assertOk()->assertJsonPath('data.inClaim', true);

        $this->travel(91)->days();
        $this->artisan('carwash:purge-evidences')->assertSuccessful();

        $paths = fn (string $order) => WorkOrderEvidence::query()->where('work_order_id', WorkOrder::query()->where('public_id', $order)->value('id'))->value('path');
        Storage::disk('private')->assertMissing($paths($first));
        Storage::disk('private')->assertExists($paths($second));
        $this->actingAs($owner)->getJson(self::API."/work-orders/$first/evidences")->assertJsonPath('data.items.0.available', false);
    }
}
