<?php

namespace Tests\Feature\Carwash;

use App\Domains\Carwash\Models\CarwashSetting;
use App\Domains\Cash\Models\CashSession;
use App\Domains\Sales\Models\Sale;
use Carbon\CarbonImmutable;

/** Reception → washing → charge in Ventas (the same FACSTO sale) → invoice → delivery. */
final class CarwashWorkOrderTest extends CarwashTestCase
{
    public function test_full_flow_charges_in_ventas_and_delivers_with_the_invoice(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);

        $order = $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($setup, ['washerPublicId' => $setup['washer']->public_id]))
            ->assertCreated()
            ->assertJsonPath('data.code', 'OT-000001')
            ->assertJsonPath('data.lines.0.price', '11.50')
            ->assertJsonPath('data.billing.status', 'UNBILLED')
            ->json('data.publicId');

        $this->actingAs($owner)->postJson(self::API."/work-orders/$order/charge")->assertConflict()->assertJsonPath('error.code', 'CARWASH_ORDER_NOT_READY_FOR_BILLING');
        foreach (['ARRIVED', 'IN_PROGRESS', 'READY'] as $status) {
            $this->actingAs($owner)->postJson(self::API."/work-orders/$order/transitions", ['status' => $status])->assertOk();
        }
        $this->actingAs($owner)->postJson(self::API."/work-orders/$order/transitions", ['status' => 'DELIVERED'])
            ->assertConflict()->assertJsonPath('error.code', 'CARWASH_ORDER_NOT_BILLED');

        $sale = $this->actingAs($owner)->postJson(self::API."/work-orders/$order/charge")
            ->assertOk()->assertJsonPath('data.billing.status', 'DRAFT')->assertJsonPath('data.billing.total', '11.50')->json('data.billing.salePublicId');
        // Charging again refreshes the same draft.
        $this->actingAs($owner)->postJson(self::API."/work-orders/$order/charge")->assertOk()->assertJsonPath('data.billing.salePublicId', $sale);
        $draft = Sale::query()->where('public_id', $sale)->sole();
        $this->assertSame(['carwash', $order, 'María Pérez'], [$draft->source_module, $draft->source_reference, $draft->customer_name]);
        $this->assertSame(1, Sale::query()->count());

        // The cashier charges it in Ventas, like any other sale.
        $this->actingAs($owner)->patchJson("/api/v1/sales/$sale", [
            'cashSessionPublicId' => CashSession::query()->where('company_id', $company->id)->value('public_id'),
            'customerPublicId' => $setup['customer']->public_id,
            'lines' => [['itemPublicId' => $setup['item']->public_id, 'quantity' => '1', 'discountAmount' => '0.00']],
            'paymentMethods' => [['fiscalPaymentMethodPublicId' => $this->paymentMethod()->public_id, 'amount' => '11.50']],
        ])->assertOk();
        $this->actingAs($owner)->postJson("/api/v1/sales/$sale/issue")->assertSuccessful();

        $this->actingAs($owner)->getJson(self::API."/work-orders/$order")->assertOk()
            ->assertJsonPath('data.billing.status', 'ISSUED')
            ->assertJsonPath('data.billing.invoiceNumber', '001-001-000000001');
        $this->actingAs($owner)->postJson(self::API."/work-orders/$order/charge")->assertConflict()->assertJsonPath('error.code', 'CARWASH_ORDER_ALREADY_BILLED');
        $this->actingAs($owner)->postJson(self::API."/work-orders/$order/transitions", ['status' => 'DELIVERED'])
            ->assertOk()->assertJsonPath('data.status', 'DELIVERED');
    }

    public function test_cancelled_draft_returns_the_order_to_unbilled_and_it_can_be_charged_again(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $order = $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($setup))->json('data.publicId');
        $this->actingAs($owner)->postJson(self::API."/work-orders/$order/transitions", ['status' => 'ARRIVED']);
        $this->actingAs($owner)->postJson(self::API."/work-orders/$order/transitions", ['status' => 'IN_PROGRESS']);
        $first = $this->actingAs($owner)->postJson(self::API."/work-orders/$order/charge")->json('data.billing.salePublicId');

        $this->actingAs($owner)->postJson("/api/v1/sales/$first/cancel")->assertOk();
        $this->actingAs($owner)->getJson(self::API."/work-orders/$order")->assertJsonPath('data.billing.status', 'UNBILLED');

        $second = $this->actingAs($owner)->postJson(self::API."/work-orders/$order/charge")->assertOk()->json('data.billing.salePublicId');
        $this->assertNotSame($first, $second);
    }

    public function test_cancel_requires_a_reason_and_no_live_sale(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $order = $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($setup))->json('data.publicId');

        $this->actingAs($owner)->postJson(self::API."/work-orders/$order/transitions", ['status' => 'CANCELLED'])->assertUnprocessable();
        $this->actingAs($owner)->postJson(self::API."/work-orders/$order/transitions", ['status' => 'READY'])
            ->assertConflict()->assertJsonPath('error.code', 'CARWASH_ORDER_TRANSITION_INVALID');
        $this->actingAs($owner)->postJson(self::API."/work-orders/$order/transitions", ['status' => 'CANCELLED', 'reason' => 'El cliente no llegó'])
            ->assertOk()->assertJsonPath('data.cancelReason', 'El cliente no llegó')->assertJsonPath('data.allowedTransitions', []);
    }

    public function test_service_without_price_for_the_vehicle_type_is_rejected(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $first = $this->carwashSetup($company, 'PBA1234');
        $second = $this->carwashSetup($company, 'PBB5678');

        $this->actingAs($owner)->postJson(self::API.'/work-orders', ['vehiclePublicId' => $first['vehicle']->public_id, 'servicePublicIds' => [$second['service']->public_id]])
            ->assertUnprocessable()->assertJsonPath('error.code', 'CARWASH_SERVICE_PRICE_MISSING');
    }

    public function test_numbering_is_per_company_and_the_agenda_respects_slot_capacity(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        CarwashSetting::for($company)->update(['vehicles_per_slot' => 1]);
        $tomorrow = CarbonImmutable::now(config('app.timezone'))->addDay()->setTime(9, 0);

        $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($setup, ['scheduledAt' => $tomorrow->toIso8601String()]))
            ->assertCreated()->assertJsonPath('data.source', 'RESERVATION');
        $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($setup, ['scheduledAt' => $tomorrow->addMinutes(10)->toIso8601String()]))
            ->assertConflict()->assertJsonPath('error.code', 'CARWASH_AGENDA_SLOT_FULL');
        $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($setup, ['scheduledAt' => $tomorrow->setTime(6, 0)->toIso8601String()]))
            ->assertUnprocessable()->assertJsonPath('error.code', 'CARWASH_AGENDA_OUTSIDE_HOURS');
        $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($setup, ['scheduledAt' => $tomorrow->addMinutes(30)->toIso8601String()]))
            ->assertCreated()->assertJsonPath('data.code', 'OT-000002');
        $this->actingAs($owner)->getJson(self::API.'/agenda?date='.$tomorrow->toDateString())->assertOk()
            ->assertJsonPath('data.slots.2.booked', 1)->assertJsonPath('data.slots.2.available', 0);

        [$other, $otherOwner] = $this->carwashCompany('1798888888001', ready: false);
        $otherSetup = $this->carwashSetup($other, 'XYZ9876');
        $this->actingAs($otherOwner)->postJson(self::API.'/work-orders', $this->orderBody($otherSetup))->assertCreated()->assertJsonPath('data.code', 'OT-000001');
    }

    public function test_dashboard_and_settings(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($setup))->assertCreated();

        $this->actingAs($owner)->getJson(self::API.'/dashboard')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.byStatus.PENDING', 1);
        $this->actingAs($owner)->putJson(self::API.'/settings', ['openingTime' => '07:00', 'closingTime' => '06:00', 'slotMinutes' => 30, 'vehiclesPerSlot' => 3])->assertUnprocessable();
        $this->actingAs($owner)->putJson(self::API.'/settings', ['openingTime' => '07:00', 'closingTime' => '19:00', 'slotMinutes' => 30, 'vehiclesPerSlot' => 3])
            ->assertOk()->assertJsonPath('data.vehiclesPerSlot', 3);
    }

    public function test_the_list_filters_by_washer_and_reports_its_cards_and_totals(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $first = $this->carwashSetup($company, 'PBA1234');
        $second = $this->carwashSetup($company, 'PBB5678');
        $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($first, ['washerPublicId' => $first['washer']->public_id]))->assertCreated();
        $started = $this->postJson(self::API.'/work-orders', $this->orderBody($second, ['washerPublicId' => $second['washer']->public_id]))->json('data.publicId');
        $this->postJson(self::API."/work-orders/$started/transitions", ['status' => 'ARRIVED'])->assertOk();
        $this->postJson(self::API."/work-orders/$started/transitions", ['status' => 'IN_PROGRESS'])->assertOk();

        $this->getJson(self::API.'/work-orders?status=IN_PROGRESS')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.total', '11.50')
            ->assertJsonPath('meta.summary', ['total' => 2, 'pending' => 1, 'inProgress' => 1, 'charged' => 0]);
        $this->getJson(self::API.'/work-orders?washerPublicId='.$first['washer']->public_id)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.vehicle.plate', 'PBA1234')
            ->assertJsonPath('meta.summary.total', 1);
    }
}
