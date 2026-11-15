<?php

namespace Tests\Feature\Carwash;

use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\Service;
use App\Domains\Carwash\Models\ServiceSupply;
use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Catalog\Models\Item;
use App\Domains\Companies\Models\Company;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/** Configuración de la Lavadora: one tab per submodule, each setting off by default. */
final class CarwashSettingsTest extends CarwashTestCase
{
    private CarbonImmutable $monday;

    protected function setUp(): void
    {
        parent::setUp();
        $this->monday = CarbonImmutable::parse('next monday', config('app.timezone'))->setTime(8, 0);
        $this->at($this->monday);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_each_tab_saves_only_its_fields_and_the_rest_keep_their_defaults(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $this->actingAs($owner)->getJson(self::API.'/settings')->assertOk()
            ->assertJsonPath('data.billingMode', 'MANUAL')->assertJsonPath('data.orderPrefix', 'OT')
            ->assertJsonPath('data.useArrivedStep', true)->assertJsonPath('data.closedWeekdays', [])->assertJsonPath('data.setupCompletedAt', null);

        $this->settings($owner, ['orderPrefix' => 'lv', 'orderStartNumber' => 100])->assertOk()
            ->assertJsonPath('data.orderPrefix', 'LV')->assertJsonPath('data.orderStartNumber', 100)->assertJsonPath('data.openingTime', '08:00');
        $this->settings($owner, ['closingTime' => '08:00'])->assertUnprocessable();
        $product = Item::query()->create(['company_id' => $company->id, 'internal_code' => 'CERA', 'type' => 'PRODUCT', 'name' => 'Cera', 'unit' => 'UND', 'sale_price' => '3', 'is_active' => true]);
        $this->settings($owner, ['dirtySurchargeItemPublicId' => $product->public_id])->assertUnprocessable();
        $this->settings($owner, ['peakTo' => '12:00'])->assertUnprocessable();

        $viewer = $this->memberAs($company, ['carwash.view']);
        $this->actingAs($viewer)->putJson(self::API.'/settings', ['orderPrefix' => 'XX'])->assertForbidden();
    }

    public function test_the_agenda_keeps_closed_days_the_advance_the_vehicles_per_slot_and_the_cleanup(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $wash = $this->areaTypes($company)[0];
        $one = $this->workArea($company, 'Box 1', [$wash], 1, '08:00', '17:00');
        $two = $this->workArea($company, 'Box 2', [$wash], 1, '08:00', '17:00');
        $tuesday = $this->monday->addDay();
        $this->settings($owner, [
            'closedWeekdays' => [7], 'closedDates' => [$tuesday->toDateString()], 'minAdvanceMinutes' => 120, 'maxAdvanceDays' => 3,
            'vehiclesPerSlot' => 1, 'cleanupMinutes' => 10,
        ])->assertOk()->assertJsonPath('data.closedWeekdays', [7]);
        $reserve = fn (CarbonImmutable $at, ?string $area, string $plate = 'PBA1234') => $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($plate === 'PBA1234' ? $setup : $this->carwashSetup($company, $plate), ['scheduledAt' => $at->toIso8601String(), 'workAreaPublicId' => $area]));

        $reserve($this->monday->setTime(9, 0), $one->public_id)->assertUnprocessable()->assertJsonPath('error.code', 'CARWASH_AGENDA_ADVANCE');
        // 40 minutes of service plus 10 of cleanup.
        $reserve($this->monday->setTime(10, 0), $one->public_id)->assertCreated()
            ->assertJsonPath('data.scheduledEndAt', $this->monday->setTime(10, 50)->toIso8601String());
        $reserve($this->monday->setTime(10, 0), $two->public_id, 'PBB1234')->assertConflict()->assertJsonPath('error.code', 'CARWASH_AGENDA_SLOT_FULL');
        $reserve($tuesday->setTime(10, 0), $one->public_id, 'PBC1234')->assertUnprocessable()->assertJsonPath('error.code', 'CARWASH_AGENDA_CLOSED');
        $reserve($this->monday->addDays(6)->setTime(10, 0), $one->public_id, 'PBD1234')->assertUnprocessable()->assertJsonPath('error.code', 'CARWASH_AGENDA_CLOSED');
        $reserve($this->monday->addDays(4)->setTime(10, 0), $one->public_id, 'PBE1234')->assertUnprocessable()->assertJsonPath('error.code', 'CARWASH_AGENDA_ADVANCE');
        $this->actingAs($owner)->getJson(self::API.'/agenda?date='.$tuesday->toDateString())->assertOk()
            ->assertJsonPath('data.closed', true)->assertJsonPath('data.slots.0.available', 0);
        // A walk-in is not a reservation: no advance needed.
        $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($this->carwashSetup($company, 'PBF1234'), ['workAreaPublicId' => $one->public_id]))->assertCreated();
    }

    public function test_hours_may_pass_midnight(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $wash = $this->areaTypes($company)[0];
        $night = $this->workArea($company, 'Box noche', [$wash], 1, '22:00', '02:00');
        // Opens at 23:00 and closes at 01:00 the next day.
        $this->settings($owner, ['openingTime' => '23:00', 'closingTime' => '01:00', 'slotMinutes' => 60])->assertOk()->assertJsonPath('data.closingTime', '01:00');
        $reserve = fn (CarbonImmutable $at, string $plate) => $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($plate === 'PBA1234' ? $setup : $this->carwashSetup($company, $plate), ['scheduledAt' => $at->toIso8601String(), 'workAreaPublicId' => $night->public_id]));

        $this->actingAs($owner)->getJson(self::API.'/agenda?date='.$this->monday->toDateString())->assertOk()
            ->assertJsonCount(2, 'data.slots')->assertJsonPath('data.slots.1.start', $this->monday->addDay()->setTime(0, 0)->toIso8601String())
            ->assertJsonPath('data.slots.1.available', 1);
        $reserve($this->monday->addDay()->setTime(0, 10), 'PBA1234')->assertCreated();
        $reserve($this->monday->setTime(22, 0), 'PBB1234')->assertUnprocessable()->assertJsonPath('error.code', 'CARWASH_AGENDA_OUTSIDE_HOURS');
        $reserve($this->monday->addDay()->setTime(0, 40), 'PBC1234')->assertUnprocessable()->assertJsonPath('error.code', 'CARWASH_AGENDA_OUTSIDE_HOURS');
    }

    public function test_one_vehicle_per_area_and_the_free_area_is_assigned(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $wash = $this->areaTypes($company)[0];
        $this->workArea($company, 'Box A', [$wash], 2, '08:00', '17:00');
        $this->workArea($company, 'Box B', [$wash], 2, '08:00', '17:00');
        $this->settings($owner, ['oneVehiclePerArea' => true, 'autoAssignArea' => true])->assertOk();
        $at = $this->monday->setTime(10, 0)->toIso8601String();
        $reserve = fn (string $plate) => $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($this->carwashSetup($company, $plate), ['scheduledAt' => $at]));

        $reserve('PBA1234')->assertCreated()->assertJsonPath('data.workArea.name', 'Box A');
        $reserve('PBB1234')->assertCreated()->assertJsonPath('data.workArea.name', 'Box B');
        $reserve('PBC1234')->assertConflict()->assertJsonPath('error.code', 'CARWASH_AGENDA_SLOT_FULL');
        $this->actingAs($owner)->getJson(self::API.'/agenda?date='.$this->monday->toDateString())->assertJsonPath('data.areas.0.capacity', 1);
    }

    public function test_the_steps_the_reception_checklist_and_delivery_without_invoice(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $this->settings($owner, ['receptionChecklist' => ['Rayones', 'Objetos de valor'], 'requireChecklist' => true])->assertOk();
        $order = $this->order($owner, $setup);

        $this->transition($owner, $order, 'ARRIVED')->assertUnprocessable()->assertJsonPath('error.code', 'CARWASH_CHECKLIST_REQUIRED');
        $this->transition($owner, $order, 'ARRIVED', ['checklist' => [['label' => 'Rayones', 'checked' => true, 'note' => 'Puerta izquierda'], ['label' => 'Objetos de valor', 'checked' => false]]])
            ->assertOk()->assertJsonPath('data.checklist.0.label', 'Rayones')->assertJsonPath('data.checklist.0.checked', true)
            ->assertJsonPath('data.checklist.0.note', 'Puerta izquierda')->assertJsonPath('data.checklist.1.checked', false);

        // Without «Llegó» and «Listo»; delivering without the invoice is allowed.
        $this->settings($owner, ['useArrivedStep' => false, 'useReadyStep' => false, 'allowUnbilledDelivery' => true, 'requireChecklist' => false])->assertOk();
        $second = $this->order($owner, $this->carwashSetup($company, 'PBB1234'));
        $this->actingAs($owner)->getJson(self::API."/work-orders/$second")->assertJsonPath('data.allowedTransitions', ['IN_PROGRESS', 'CANCELLED'])
            ->assertJsonPath('data.receptionStatus', 'IN_PROGRESS');
        $this->transition($owner, $second, 'ARRIVED')->assertConflict()->assertJsonPath('error.code', 'CARWASH_ORDER_TRANSITION_INVALID');
        $this->transition($owner, $second, 'IN_PROGRESS')->assertOk()->assertJsonPath('data.allowedTransitions', ['DELIVERED']);
        $this->transition($owner, $second, 'DELIVERED')->assertOk()->assertJsonPath('data.status', 'DELIVERED');
    }

    public function test_the_prefix_the_start_number_and_the_automatic_charge(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $this->settings($owner, ['orderPrefix' => 'LV', 'orderStartNumber' => 50, 'billingMode' => 'AUTO'])->assertOk();

        $this->actingAs($owner)->getJson(self::API.'/work-orders/next-code')->assertJsonPath('data.code', 'LV-000050');
        $order = $this->order($owner, $setup, 'LV-000050');
        $this->actingAs($owner)->getJson(self::API.'/work-orders?search=LV-50')->assertJsonPath('data.0.publicId', $order);
        foreach (['ARRIVED', 'IN_PROGRESS'] as $status) {
            $this->transition($owner, $order, $status)->assertOk()->assertJsonPath('data.billing.status', 'UNBILLED');
        }
        // The wash ends: the sale draft is already waiting in Ventas.
        $this->transition($owner, $order, 'READY')->assertOk()->assertJsonPath('data.billing.status', 'DRAFT')->assertJsonPath('data.billing.total', '11.50');

        $this->settings($owner, ['orderPrefix' => 'OT'])->assertOk();
        $this->actingAs($owner)->getJson(self::API."/work-orders/$order")->assertJsonPath('data.code', 'LV-000050');
        $this->order($owner, $this->carwashSetup($company, 'PBB1234'), 'OT-000051');
    }

    public function test_vehicles_ask_for_the_required_data_and_an_ecuadorian_plate(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $this->settings($owner, ['requireColor' => true, 'validateEcPlate' => true])->assertOk();
        $vehicle = fn (array $data) => $this->actingAs($owner)->postJson(self::API.'/vehicles', ['customerPublicId' => $setup['customer']->public_id, 'vehicleTypePublicId' => $setup['type']->public_id, ...$data]);

        $vehicle(['plate' => 'AB12345', 'color' => 'Rojo'])->assertUnprocessable()->assertJsonPath('error.code', 'CARWASH_VEHICLE_DATA_REQUIRED');
        $vehicle(['plate' => 'GBC0123'])->assertUnprocessable()->assertJsonPath('error.details.errors.color.0', 'El color es obligatorio.');
        $vehicle(['plate' => 'gbc-0123', 'color' => 'Rojo'])->assertCreated();
        $vehicle(['plate' => 'AB123C', 'color' => 'Negro'])->assertCreated();
    }

    public function test_single_price_and_the_surcharges_for_dirt_and_peak_hours(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $dirt = $this->serviceItem($company, 'REC-SUC', 'Recargo suciedad extrema', '2.00');
        $peak = $this->serviceItem($company, 'REC-PIC', 'Recargo hora pico', '1.00');
        $this->settings($owner, ['pricingMode' => 'SINGLE', 'dirtySurchargeItemPublicId' => $dirt->public_id, 'peakSurchargeItemPublicId' => $peak->public_id, 'peakFrom' => '10:00', 'peakTo' => '12:00'])
            ->assertOk()->assertJsonPath('data.dirtySurcharge.name', 'Recargo suciedad extrema');

        // 08:00 is not peak time; the single price is the service's own item ($9.00 + IVA).
        $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($setup))->assertCreated()
            ->assertJsonPath('data.lines.0.description', 'Lavado completo PBA1234')->assertJsonPath('data.total', '10.35')->assertJsonCount(1, 'data.lines');
        $this->at($this->monday->setTime(10, 30));
        $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($setup, ['dirty' => true]))->assertCreated()
            ->assertJsonPath('data.dirty', true)->assertJsonPath('data.lines.1.kind', 'SURCHARGE')->assertJsonPath('data.lines.1.description', 'Recargo suciedad extrema')
            ->assertJsonPath('data.lines.2.description', 'Recargo hora pico')->assertJsonPath('data.total', '13.80');
    }

    public function test_combos_and_rounding_discount_the_sale_lines(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $vacuum = Service::query()->create(['company_id' => $company->id, 'item_id' => $this->serviceItem($company, 'ASP', 'Aspirado', '5.00')->id, 'duration_minutes' => 20]);
        $this->settings($owner, ['pricingMode' => 'SINGLE'])->assertOk();
        $this->actingAs($owner)->postJson(self::API.'/combos', ['name' => 'Lavado + aspirado', 'discountPercent' => '10', 'servicePublicIds' => [$setup['service']->public_id, $vacuum->public_id]])
            ->assertCreated()->assertJsonCount(2, 'data.services');
        $this->actingAs($owner)->postJson(self::API.'/combos', ['name' => 'lavado + ASPIRADO', 'discountPercent' => '5', 'servicePublicIds' => [$setup['service']->public_id, $vacuum->public_id]])
            ->assertConflict();

        $charge = function (string $plate) use ($owner, $company, $vacuum): Sale {
            $order = $this->order($owner, ['vehicle' => $this->carwashSetup($company, $plate)['vehicle'], 'service' => Service::query()->where('company_id', $company->id)->orderBy('id')->first()], extra: ['servicePublicIds' => [Service::query()->where('company_id', $company->id)->orderBy('id')->first()->public_id, $vacuum->public_id]]);
            $this->transition($owner, $order, 'ARRIVED');
            $this->transition($owner, $order, 'IN_PROGRESS');
            $sale = $this->actingAs($owner)->postJson(self::API."/work-orders/$order/charge")->assertOk()->json('data.billing.salePublicId');

            return Sale::query()->where('public_id', $sale)->sole();
        };
        // 9.00 + 5.00 with 10 %: 8.10 + 1.22 and 4.50 + 0.68 (IVA per line).
        $sale = $charge('PBB1234');
        $this->assertSame(['1.40', '14.50'], [(string) $sale->discount_total, (string) $sale->total]);
        $this->assertStringContainsString('Combo Lavado + aspirado', (string) $sale->notes);

        // Rounded down to the dollar.
        $this->settings($owner, ['roundingStep' => '1.00'])->assertOk();
        $this->assertSame('14.00', (string) $charge('PBC1234')->total);
    }

    public function test_every_nth_delivered_order_of_the_customer_has_its_service_free(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $this->settings($owner, ['loyaltyEvery' => 2])->assertOk();
        $first = $this->order($owner, $setup);
        WorkOrder::query()->where('public_id', $first)->update(['status' => WorkOrderStatus::DELIVERED->value, 'delivered_at' => now()]);

        $second = $this->order($owner, $setup);
        $this->transition($owner, $second, 'ARRIVED');
        $this->transition($owner, $second, 'IN_PROGRESS');
        $this->actingAs($owner)->postJson(self::API."/work-orders/$second/charge")->assertOk()
            ->assertJsonPath('data.loyaltyFree', true)->assertJsonPath('data.billing.total', '0.00');
    }

    public function test_rotation_the_maximum_per_washer_and_the_commissions(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $other = $this->memberAs($company, ['carwash.assignable']);
        $other->forceFill(['name' => 'Ana'])->save();
        $this->settings($owner, ['assignmentMode' => 'ROTATION', 'maxOrdersPerWasher' => 1, 'allowUnbilledDelivery' => true, 'commissionType' => 'PERCENT', 'commissionValue' => '10'])->assertOk();

        $first = $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($setup))->assertCreated()->json('data');
        $this->transition($owner, $first['publicId'], 'ARRIVED')->assertOk();
        $second = $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($this->carwashSetup($company, 'PBB1234')))->assertCreated()->json('data');
        $this->assertNotSame($first['washer']['publicId'], $second['washer']['publicId']);
        $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($this->carwashSetup($company, 'PBC1234'), ['washerPublicId' => $first['washer']['publicId']]))
            ->assertConflict()->assertJsonPath('error.code', 'CARWASH_WASHER_BUSY');

        foreach (['IN_PROGRESS', 'READY', 'DELIVERED'] as $status) {
            $this->transition($owner, $first['publicId'], $status)->assertOk();
        }
        // 10 % of the $10.00 base.
        $this->actingAs($owner)->getJson(self::API.'/commissions')->assertOk()
            ->assertJsonPath('data.washers.0.washer.publicId', $first['washer']['publicId'])->assertJsonPath('data.washers.0.orders', 1)
            ->assertJsonPath('data.washers.0.base', '10.00')->assertJsonPath('data.washers.0.commission', '1.00');
        $this->actingAs($owner)->patchJson(self::API.'/services/'.$setup['service']->public_id, ['commissionType' => 'FIXED', 'commissionValue' => '2.50'])
            ->assertOk()->assertJsonPath('data.commissionType', 'FIXED');
        $this->actingAs($owner)->getJson(self::API.'/commissions')->assertJsonPath('data.total', '2.50');
        $this->actingAs($this->memberAs($company, ['carwash.view']))->getJson(self::API.'/commissions')->assertForbidden();
    }

    public function test_supplies_show_their_stock_and_the_dashboard_warns_when_low(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $shampoo = $this->actingAs($owner)->postJson('/api/v1/items', ['type' => 'PRODUCT', 'internalCode' => 'SHAM', 'name' => 'Shampoo', 'unit' => 'UND', 'salePrice' => '5', 'stock' => $this->productStock($company, '3')])
            ->assertCreated()->json('data.publicId');
        ServiceSupply::query()->create(['company_id' => $company->id, 'service_price_id' => $setup['price']->id, 'item_id' => Item::query()->where('public_id', $shampoo)->value('id'), 'quantity' => '1']);

        $this->actingAs($owner)->getJson(self::API.'/supplies')->assertOk()
            ->assertJsonPath('data.0.name', 'Shampoo')->assertJsonPath('data.0.stock', '3.000000')->assertJsonPath('data.0.low', true);
        $this->actingAs($owner)->getJson(self::API.'/dashboard')->assertJsonPath('data.lowSupplies.0.name', 'Shampoo');
        $this->settings($owner, ['supplyAlert' => false])->assertOk();
        $this->actingAs($owner)->getJson(self::API.'/dashboard')->assertJsonPath('data.lowSupplies', []);
    }

    public function test_a_reservation_without_arrival_is_released_after_the_tolerance(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $area = $this->workArea($company, 'Box 1', [$this->areaTypes($company)[0]], 1, '08:00', '17:00');
        $this->settings($owner, ['arrivalToleranceMinutes' => 15])->assertOk();
        $order = $this->order($owner, $setup, extra: ['scheduledAt' => $this->monday->setTime(10, 0)->toIso8601String(), 'workAreaPublicId' => $area->public_id]);

        $this->at($this->monday->setTime(10, 10));
        $this->artisan('carwash:release-no-shows')->assertSuccessful();
        $this->assertSame(WorkOrderStatus::PENDING, WorkOrder::query()->where('public_id', $order)->value('status'));
        $this->at($this->monday->setTime(10, 20));
        $this->artisan('carwash:release-no-shows')->assertSuccessful();
        $this->actingAs($owner)->getJson(self::API."/work-orders/$order")->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.cancelReason', 'No llegó (tolerancia de 15 minutos)');
    }

    public function test_the_assistant_lists_what_is_pending_until_it_is_completed(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $this->actingAs($owner)->getJson(self::API.'/settings/pending')->assertOk()
            ->assertJsonPath('data.setupCompletedAt', null)->assertJsonPath('data.items.1.key', 'areas')->assertJsonPath('data.items.1.done', false);
        $this->workArea($company, 'Box 1', [$this->areaTypes($company)[0]], 1, '08:00', '17:00');
        $this->actingAs($owner)->getJson(self::API.'/settings/pending')->assertJsonPath('data.items.1.done', true);
        $this->actingAs($owner)->postJson(self::API.'/settings/setup-complete')->assertOk();
        $this->assertNotNull($this->actingAs($owner)->getJson(self::API.'/settings/pending')->json('data.setupCompletedAt'));
    }

    private function at(CarbonImmutable $moment): void
    {
        CarbonImmutable::setTestNow($moment);
        Carbon::setTestNow($moment);
    }

    /** @param array<string, mixed> $data */
    private function settings(User $owner, array $data): TestResponse
    {
        return $this->actingAs($owner)->putJson(self::API.'/settings', $data);
    }

    /** @param array<string, mixed> $extra */
    private function order(User $owner, array $setup, ?string $code = null, array $extra = []): string
    {
        $response = $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($setup, $extra))->assertCreated();
        if ($code !== null) {
            $response->assertJsonPath('data.code', $code);
        }

        return $response->json('data.publicId');
    }

    /** @param array<string, mixed> $extra */
    private function transition(User $owner, string $order, string $status, array $extra = []): TestResponse
    {
        return $this->actingAs($owner)->postJson(self::API."/work-orders/$order/transitions", ['status' => $status, ...$extra]);
    }

    private function serviceItem(Company $company, string $code, string $name, string $price): Item
    {
        $item = Item::query()->create(['company_id' => $company->id, 'internal_code' => $code, 'type' => 'SERVICE', 'name' => $name, 'unit' => 'UND', 'sale_price' => $price, 'is_active' => true]);
        $item->taxes()->attach($this->companyIva($company, '4')->id, ['company_id' => $company->id]);

        return $item;
    }
}
