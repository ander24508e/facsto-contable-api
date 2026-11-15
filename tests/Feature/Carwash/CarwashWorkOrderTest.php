<?php

namespace Tests\Feature\Carwash;

use App\Domains\Carwash\Models\Vehicle;
use App\Domains\Cash\Models\CashSession;
use App\Domains\Customers\Enums\IdentificationType;
use App\Domains\Customers\Models\Customer;
use App\Domains\Sales\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

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

    public function test_numbering_is_per_company_and_a_reservation_takes_its_area_for_the_services_duration(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        [$wash, $dry, $lube] = $this->areaTypes($company);
        $area = $this->workArea($company, 'Box 1', [$wash, $dry], 1, '08:00', '17:00');
        $setup['service']->update(['area_type_id' => $wash->id]);
        $tomorrow = CarbonImmutable::now(config('app.timezone'))->addDay()->setTime(9, 0);
        $reserve = fn (CarbonImmutable $at, ?string $area) => $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($setup, ['scheduledAt' => $at->toIso8601String(), 'workAreaPublicId' => $area]));

        $reserve($tomorrow, null)->assertUnprocessable()->assertJsonPath('error.code', 'CARWASH_WORK_AREA_NOT_FOUND');
        // The service lasts 40 minutes: the area is busy from 09:00 to 09:40.
        $reserve($tomorrow, $area->public_id)->assertCreated()->assertJsonPath('data.source', 'RESERVATION')
            ->assertJsonPath('data.workArea.name', 'Box 1')->assertJsonPath('data.scheduledEndAt', $tomorrow->addMinutes(40)->toIso8601String());
        $reserve($tomorrow->addMinutes(30), $area->public_id)->assertConflict()->assertJsonPath('error.code', 'CARWASH_AGENDA_SLOT_FULL');
        $reserve($tomorrow->setTime(6, 0), $area->public_id)->assertUnprocessable()->assertJsonPath('error.code', 'CARWASH_AGENDA_OUTSIDE_HOURS');
        // It must end before the area closes at 17:00.
        $reserve($tomorrow->setTime(16, 30), $area->public_id)->assertUnprocessable()->assertJsonPath('error.code', 'CARWASH_AGENDA_OUTSIDE_HOURS');
        $reserve($tomorrow->addMinutes(40), $area->public_id)->assertCreated()->assertJsonPath('data.code', 'OT-000002');
        $this->actingAs($owner)->getJson(self::API.'/agenda?date='.$tomorrow->toDateString())->assertOk()
            ->assertJsonPath('data.areas.0.name', 'Box 1')
            ->assertJsonPath('data.slots.2.booked', 1)->assertJsonPath('data.slots.2.available', 0)
            ->assertJsonPath('data.slots.0.capacity', 1)->assertJsonPath('data.slots.18.capacity', 0);

        // The area does not do lubrication.
        $setup['service']->update(['area_type_id' => $lube->id]);
        $reserve($tomorrow->setTime(14, 0), $area->public_id)->assertUnprocessable()->assertJsonPath('error.code', 'CARWASH_WORK_AREA_TYPE_MISMATCH');

        [$other, $otherOwner] = $this->carwashCompany('1798888888001', ready: false);
        $otherSetup = $this->carwashSetup($other, 'XYZ9876');
        $this->actingAs($otherOwner)->postJson(self::API.'/work-orders', $this->orderBody($otherSetup))->assertCreated()->assertJsonPath('data.code', 'OT-000001');
    }

    public function test_a_walk_in_with_an_area_takes_it_from_now(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $area = $this->workArea($company, 'Box 1', [$this->areaTypes($company)[0]], 1, '08:00', '17:00');
        $now = CarbonImmutable::now(config('app.timezone'))->addDay()->setTime(10, 15, 30);
        CarbonImmutable::setTestNow($now);
        Carbon::setTestNow($now);

        $this->actingAs($owner)->getJson(self::API.'/work-orders/next-code')->assertOk()->assertJsonPath('data.code', 'OT-000001');
        $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($setup, ['workAreaPublicId' => $area->public_id, 'notes' => 'Sin aspirado']))
            ->assertCreated()->assertJsonPath('data.source', 'COUNTER')->assertJsonPath('data.workArea.name', 'Box 1')
            ->assertJsonPath('data.scheduledAt', $now->startOfMinute()->toIso8601String())
            ->assertJsonPath('data.scheduledEndAt', $now->startOfMinute()->addMinutes(40)->toIso8601String())
            ->assertJsonPath('data.notes', 'Sin aspirado')->assertJsonPath('data.createdBy.name', $owner->name);
        // The area is busy until 10:55: another walk-in does not fit.
        $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($this->carwashSetup($company, 'XYZ9876'), ['workAreaPublicId' => $area->public_id]))
            ->assertConflict()->assertJsonPath('error.code', 'CARWASH_AGENDA_SLOT_FULL');
        $this->actingAs($owner)->getJson(self::API.'/work-orders/next-code')->assertOk()->assertJsonPath('data.code', 'OT-000002');

        CarbonImmutable::setTestNow($now->setTime(20, 0));
        Carbon::setTestNow($now->setTime(20, 0));
        $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($this->carwashSetup($company, 'QWE1234'), ['workAreaPublicId' => $area->public_id]))
            ->assertUnprocessable()->assertJsonPath('error.code', 'CARWASH_AGENDA_OUTSIDE_HOURS');
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
    }

    public function test_dashboard_and_settings(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($setup))->assertCreated();

        $this->actingAs($owner)->getJson(self::API.'/dashboard')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.byStatus.PENDING', 1);
        $this->actingAs($owner)->putJson(self::API.'/settings', ['openingTime' => '07:00', 'closingTime' => '07:00', 'slotMinutes' => 30])->assertUnprocessable();
        $this->actingAs($owner)->putJson(self::API.'/settings', ['openingTime' => '07:00', 'closingTime' => '19:00', 'slotMinutes' => 30])
            ->assertOk()->assertJsonPath('data.closingTime', '19:00');
    }

    public function test_work_areas_take_one_or_two_area_types_and_unique_names(): void
    {
        [$company, $owner] = $this->carwashCompany();
        // Lavado, Secado and Lubricación come fixed; the company adds its own.
        $types = $this->actingAs($owner)->getJson(self::API.'/area-types')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name', 'Lavado')->assertJsonPath('data.0.isFixed', true)->json('data');
        [$wash, $dry, $lube] = array_column($types, 'publicId');
        $this->actingAs($owner)->patchJson(self::API.'/area-types/'.$wash, ['isActive' => false])->assertUnprocessable()->assertJsonPath('error.code', 'CARWASH_AREA_TYPE_FIXED');
        $bar = $this->actingAs($owner)->postJson(self::API.'/area-types', ['name' => 'Bar'])->assertCreated()
            ->assertJsonPath('data.isFixed', false)->assertJsonPath('data.isActive', true)->json('data.publicId');
        $this->actingAs($owner)->postJson(self::API.'/area-types', ['name' => 'bar'])->assertConflict();

        $body = ['name' => 'Box 1', 'areaTypePublicIds' => [$dry, $wash], 'capacity' => 2, 'opensAt' => '08:00', 'closesAt' => '18:00'];
        $this->actingAs($owner)->postJson(self::API.'/work-areas', [...$body, 'areaTypePublicIds' => [$wash, $dry, $lube]])->assertUnprocessable()->assertJsonValidationErrors(['areaTypePublicIds'], 'error.details.errors');
        $this->actingAs($owner)->postJson(self::API.'/work-areas', [...$body, 'closesAt' => '08:00'])->assertUnprocessable()->assertJsonValidationErrors(['closesAt'], 'error.details.errors');
        // A new area comes back active, so the list shows it without reloading.
        $area = $this->actingAs($owner)->postJson(self::API.'/work-areas', $body)->assertCreated()
            ->assertJsonPath('data.isActive', true)
            ->assertJsonPath('data.types.0.name', 'Lavado')->assertJsonPath('data.types.1.name', 'Secado')->json('data.publicId');
        // A turned-off type is not offered to new areas.
        $this->actingAs($owner)->patchJson(self::API.'/area-types/'.$bar, ['isActive' => false])->assertOk()->assertJsonPath('data.isActive', false);
        $this->actingAs($owner)->postJson(self::API.'/work-areas', [...$body, 'name' => 'Barra', 'areaTypePublicIds' => [$bar]])->assertUnprocessable()->assertJsonValidationErrors(['areaTypePublicIds'], 'error.details.errors');
        $this->actingAs($owner)->postJson(self::API.'/work-areas', [...$body, 'name' => 'box 1'])->assertConflict();
        $this->actingAs($owner)->patchJson(self::API.'/work-areas/'.$area, ['isActive' => false, 'capacity' => 3])->assertOk()
            ->assertJsonPath('data.isActive', false)->assertJsonPath('data.capacity', 3);
        $this->actingAs($owner)->getJson(self::API.'/work-areas')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($owner)->getJson(self::API.'/work-areas?includeInactive=1')->assertOk()->assertJsonCount(1, 'data');
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

    public function test_the_same_plate_is_the_same_vehicle_and_each_order_keeps_its_customer(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $other = Customer::query()->create(['company_id' => $company->id, 'identification_type' => IdentificationType::CEDULA, 'identification' => '1713175071', 'legal_name' => 'Juan Torres', 'is_active' => true]);
        $body = ['servicePublicIds' => [$setup['service']->public_id], 'vehicleSpecPublicId' => $setup['spec']->public_id];

        // By its plate (written in any way) and with another customer: no duplicate, the order is his.
        $this->actingAs($owner)->postJson(self::API.'/work-orders', [...$body, 'plate' => 'pba-1234', 'customerPublicId' => $other->public_id])->assertCreated()
            ->assertJsonPath('data.vehicle.plate', 'PBA1234')->assertJsonPath('data.customer.name', 'Juan Torres');
        $this->assertSame(1, Vehicle::query()->where('company_id', $company->id)->count());
        $this->assertSame($other->id, $setup['vehicle']->refresh()->customer_id);

        // A new plate is registered with the order, without being tied to a customer in Vehículos.
        $this->actingAs($owner)->postJson(self::API.'/work-orders', [...$body, 'plate' => 'PBX4444', 'customerPublicId' => $setup['customer']->public_id])->assertCreated()
            ->assertJsonPath('data.vehicle.plate', 'PBX4444')->assertJsonPath('data.customer.name', 'María Pérez');
        $this->assertSame(2, Vehicle::query()->where('company_id', $company->id)->count());
        $this->actingAs($owner)->postJson(self::API.'/vehicles', ['plate' => 'PBC1234', 'vehicleSpecPublicId' => $setup['spec']->public_id])->assertCreated()
            ->assertJsonPath('data.customer.publicId', null);
        // Registering an existing plate by hand in Vehículos is still a duplicate.
        $this->actingAs($owner)->postJson(self::API.'/vehicles', ['plate' => 'PBA1234', 'vehicleSpecPublicId' => $setup['spec']->public_id])->assertStatus(409);
    }
}
