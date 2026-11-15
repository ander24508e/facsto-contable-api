<?php

namespace Tests\Feature\Carwash;

use App\Domains\Carwash\Models\CarwashNotification;
use Carbon\CarbonImmutable;

/** Bandeja de avisos y «Avisar por WhatsApp», Órdenes › Reportes y el historial del vehículo al buscar su placa. */
final class CarwashNoticesAndReportsTest extends CarwashTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function deliver(object $test, $owner, string $order): void
    {
        foreach (['ARRIVED', 'IN_PROGRESS', 'READY'] as $status) {
            $test->actingAs($owner)->postJson(self::API."/work-orders/$order/transitions", ['status' => $status])->assertOk();
        }
    }

    public function test_the_ready_aviso_waits_in_the_bandeja_and_opens_whatsapp(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $setup['customer']->forceFill(['phone' => '0991234567'])->save();
        $this->actingAs($owner)->putJson(self::API.'/settings', ['notifyReady' => true])->assertOk();
        $order = $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($setup))->assertCreated()->json('data.publicId');

        $this->deliver($this, $owner, $order);
        $this->assertSame(1, CarwashNotification::query()->where('kind', 'READY')->count());
        $notice = $this->actingAs($owner)->getJson(self::API.'/notifications')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertStringStartsWith('https://wa.me/593991234567?text=', $notice['whatsappUrl']);
        $this->assertSame('Hola María Pérez, su orden Lavado completo PBA1234 de su vehículo PBA1234 está culminada. ¡Gracias por preferirnos!', $notice['message']);

        // «Avisar por WhatsApp» from the order reuses the same aviso and leaves it opened.
        $this->actingAs($owner)->postJson(self::API."/work-orders/$order/whatsapp")->assertOk()->assertJsonPath('data.status', 'OPENED');
        $this->assertSame(1, CarwashNotification::query()->count());
        $this->actingAs($owner)->getJson(self::API.'/notifications')->assertJsonCount(0, 'data');
    }

    public function test_reminders_are_queued_once_for_the_next_reservations(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $wash = $this->areaTypes($company)[0];
        $area = $this->workArea($company, 'Box 1', [$wash], 1, '00:00', '23:59');
        $this->actingAs($owner)->putJson(self::API.'/settings', ['notifyReminder' => true, 'reminderHours' => 24, 'openingTime' => '00:00', 'closingTime' => '23:59'])->assertOk();
        $at = CarbonImmutable::now(config('app.timezone'))->addHours(3)->startOfHour();
        $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($setup, ['scheduledAt' => $at->toIso8601String(), 'workAreaPublicId' => $area->public_id]))->assertCreated();

        $this->artisan('carwash:queue-reminders')->assertSuccessful();
        $this->artisan('carwash:queue-reminders')->assertSuccessful();
        $this->assertSame(1, CarwashNotification::query()->where('kind', 'REMINDER')->count());
    }

    public function test_reports_and_the_vehicle_history(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $this->actingAs($owner)->putJson(self::API.'/settings', ['allowUnbilledDelivery' => true, 'loyaltyEvery' => 3])->assertOk();
        $order = $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($setup))->assertCreated()->json('data.publicId');
        $this->deliver($this, $owner, $order);
        $this->actingAs($owner)->postJson(self::API."/work-orders/$order/transitions", ['status' => 'DELIVERED'])->assertOk();

        $this->actingAs($owner)->getJson(self::API.'/reports')->assertOk()
            ->assertJsonPath('data.orders', 1)->assertJsonPath('data.services.0.name', 'Lavado completo PBA1234')
            ->assertJsonPath('data.noShows', 0)->assertJsonStructure(['data' => ['areas', 'steps' => ['waiting', 'washing', 'pickup', 'total'], 'commissions']]);
        $viewer = $this->memberAs($company, ['carwash.view']);
        $this->actingAs($viewer)->getJson(self::API.'/reports')->assertOk()->assertJsonPath('data.commissions', null);

        $this->actingAs($owner)->getJson(self::API.'/vehicles/lookup?plate=PBA1234')->assertOk()
            ->assertJsonPath('data.history.visits', 1)->assertJsonPath('data.history.last.0.code', 'OT-000001')
            ->assertJsonPath('data.history.loyalty.remaining', 2);
    }
}
