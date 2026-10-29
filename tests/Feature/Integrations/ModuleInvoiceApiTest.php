<?php

namespace Tests\Feature\Integrations;

use App\Domains\Catalog\Models\Item;
use App\Domains\Companies\Models\Company;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Integrations\Models\WebhookDelivery;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Sales\InvoiceFeatureTestCase;
use Tests\Support\FakesSriWebServices;

/** POST /api/v1/invoices: a module invoices with its token, without duplicates, and is told the SRI answer. */
final class ModuleInvoiceApiTest extends InvoiceFeatureTestCase
{
    use FakesSriWebServices;

    private const WEBHOOK = 'https://taller.example.com/facsto/webhook';

    public function test_a_module_invoices_once_per_idempotency_key_and_receives_a_signed_webhook(): void
    {
        [$company, $token, $secret] = $this->moduleCompany();
        $body = [
            'sourceReference' => 'OT-5541',
            'customer' => ['identification' => '1710034065', 'legalName' => 'Ana Pérez', 'email' => 'ana@example.com'],
            'lines' => [['itemCode' => 'SERV-1', 'quantity' => '2']],
        ];

        $first = $this->withToken($token)->postJson('/api/v1/invoices', $body, ['Idempotency-Key' => 'OT-5541-1'])
            ->assertCreated()
            ->assertJsonPath('data.invoice.status', 'AUTHORIZED')
            ->assertJsonPath('data.invoice.totals.total', '20.00')
            ->assertJsonPath('data.invoice.customer.identification', '1710034065')
            ->assertJsonPath('meta.replayed', false)
            ->json('data');

        $this->withToken($token)->postJson('/api/v1/invoices', $body, ['Idempotency-Key' => 'OT-5541-1'])
            ->assertOk()->assertJsonPath('meta.replayed', true)->assertJsonPath('data.salePublicId', $first['salePublicId']);
        $this->withToken($token)->postJson('/api/v1/invoices', [...$body, 'sourceReference' => 'OT-9'], ['Idempotency-Key' => 'OT-5541-1'])
            ->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
        $this->assertSame(1, Sale::query()->where('company_id', $company->id)->count());

        $delivery = WebhookDelivery::query()->firstOrFail();
        $this->assertSame('invoice.authorized', $delivery->event);
        $this->assertSame('DELIVERED', $delivery->status);
        Http::assertSent(function (Request $request) use ($secret): bool {
            if ($request->url() !== self::WEBHOOK) {
                return false;
            }
            $expected = 'sha256='.hash_hmac('sha256', $request->header('X-Facsto-Timestamp')[0].'.'.$request->body(), $secret);

            return $request->header('X-Facsto-Signature')[0] === $expected && $request['data']['sourceReference'] === 'OT-5541';
        });

        $this->withToken($token)->getJson('/api/v1/module/invoices/'.$first['invoice']['publicId'])->assertOk()->assertJsonPath('data.status', 'AUTHORIZED');
        $this->withToken($token)->get('/api/v1/module/invoices/'.$first['invoice']['publicId'].'/ride')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_the_token_and_the_idempotency_key_are_required_and_items_must_exist(): void
    {
        [, $token] = $this->moduleCompany();
        $body = ['lines' => [['itemCode' => 'SERV-1', 'quantity' => '1']]];

        $this->withToken('fct_invalido')->postJson('/api/v1/invoices', $body, ['Idempotency-Key' => 'x'])->assertUnauthorized()
            ->assertJsonPath('error.code', 'API_CLIENT_INVALID');
        $this->withToken($token)->postJson('/api/v1/invoices', $body)->assertUnprocessable()->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
        $this->withToken($token)->postJson('/api/v1/invoices', ['lines' => [['itemCode' => 'NO-EXISTE', 'quantity' => '1']]], ['Idempotency-Key' => 'y'])
            ->assertUnprocessable()->assertJsonPath('error.details.itemCodes', ['NO-EXISTE']);
    }

    public function test_a_revoked_token_stops_working_and_a_module_never_sees_other_documents(): void
    {
        [$company, $token] = $this->moduleCompany();
        $owner = $company->memberships()->firstOrFail()->user;
        $data = $this->withToken($token)->postJson('/api/v1/invoices', ['lines' => [['itemCode' => 'SERV-1', 'quantity' => '1']]], ['Idempotency-Key' => 'z'])
            ->assertCreated()->assertJsonPath('data.invoice.customer.identification', '9999999999999')->json('data');
        $ownSale = $this->makeSale($owner, $company);
        $ownInvoice = $this->actingAs($owner)->postJson("/api/v1/sales/{$ownSale->public_id}/issue")->json('data.publicId');

        $this->withToken($token)->getJson("/api/v1/module/invoices/{$ownInvoice}")->assertNotFound();

        $client = $this->actingAs($owner)->getJson('/api/v1/api-clients')->assertOk()->json('data.0.publicId');
        $this->actingAs($owner)->deleteJson("/api/v1/api-clients/{$client}")->assertOk()->assertJsonPath('data.isActive', false);
        $this->withToken($token)->getJson('/api/v1/module/invoices/'.$data['invoice']['publicId'])->assertUnauthorized();
    }

    /** @return array{0: Company, 1: string, 2: string} */
    private function moduleCompany(): array
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $this->enableSriTransmission($company);
        $item = Item::query()->create(['company_id' => $company->id, 'internal_code' => 'SERV-1', 'type' => 'SERVICE', 'name' => 'Servicio', 'unit' => 'UND', 'sale_price' => '10.000000', 'is_active' => true]);
        $item->taxes()->attach($this->companyIva($company, '0')->id, ['company_id' => $company->id]);
        Http::fake(function (Request $request) {
            if ($request->url() === self::WEBHOOK) {
                return Http::response(['ok' => true]);
            }
            if (str_contains($request->url(), 'Recepcion')) {
                return Http::response($this->receptionResponse('RECIBIDA'));
            }
            $document = ElectronicDocument::query()->latest('id')->firstOrFail();
            $signed = Storage::disk('private')->get($document->artifacts()->where('type', 'SIGNED_XML')->firstOrFail()->path);

            return Http::response($this->authorizationResponse($document->access_key, 'AUTORIZADO', $signed));
        });

        $created = $this->actingAs($user)->postJson('/api/v1/api-clients', ['name' => 'Taller Norte', 'module' => 'workshop', 'webhookUrl' => self::WEBHOOK])
            ->assertCreated()->json('data');
        $this->assertStringStartsWith('fct_', $created['token']);
        auth()->guard('web')->logout();

        return [$company, $created['token'], $created['webhookSecret']];
    }
}
