<?php

namespace Tests\Feature\Carwash;

use App\Domains\Modules\Enums\EntitlementStatus;
use App\Models\User;

/** The Lavadora exists for a company only with its entitlement, and each role does what the manifest says. */
final class CarwashAccessTest extends CarwashTestCase
{
    public function test_without_entitlement_the_module_does_not_exist(): void
    {
        $user = User::factory()->create();
        $this->readyCompany($user);

        $this->actingAs($user)->getJson(self::API.'/work-orders')->assertForbidden()->assertJsonPath('error.code', 'MODULE_NOT_ENABLED');
        $this->actingAs($user)->getJson('/api/v1/company')->assertOk()->assertJsonPath('data.modules', []);
    }

    public function test_company_lists_its_modules_with_the_actions_of_the_role(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $viewer = $this->member($company, 'VIEWER');

        $this->actingAs($owner)->getJson('/api/v1/company')->assertOk()
            ->assertJsonPath('data.modules.0.module', 'carwash')
            ->assertJsonPath('data.modules.0.status', 'ENABLED')
            ->assertJsonPath('data.modules.0.features', ['carwash'])
            ->assertJsonPath('data.modules.0.can', ['view', 'operate', 'manage', 'own_work', 'assignable']);
        $this->actingAs($viewer)->getJson('/api/v1/company')->assertOk()->assertJsonPath('data.modules.0.can', ['view']);
    }

    public function test_read_only_keeps_reading_but_never_writes(): void
    {
        [$company, $owner] = $this->carwashCompany(status: EntitlementStatus::READ_ONLY);
        $setup = $this->carwashSetup($company);

        $this->actingAs($owner)->getJson(self::API.'/vehicles')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($owner)->postJson(self::API.'/work-orders', $this->orderBody($setup))
            ->assertForbidden()->assertJsonPath('error.code', 'MODULE_READ_ONLY');
        $this->actingAs($owner)->getJson('/api/v1/company')->assertJsonPath('data.modules.0.can', ['view', 'own_work']);
    }

    public function test_viewer_reads_biller_operates_only_owner_and_admin_configure(): void
    {
        [$company] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $viewer = $this->member($company, 'VIEWER');
        $biller = $this->member($company, 'BILLER');

        $this->actingAs($viewer)->getJson(self::API.'/vehicles')->assertOk();
        $this->actingAs($viewer)->postJson(self::API.'/work-orders', $this->orderBody($setup))->assertForbidden();
        $this->actingAs($biller)->postJson(self::API.'/work-orders', $this->orderBody($setup))->assertCreated();
        $this->actingAs($biller)->patchJson(self::API.'/services/'.$setup['service']->public_id, ['durationMinutes' => 30])->assertForbidden();
        $this->actingAs($biller)->putJson(self::API.'/categories', ['categoryPublicIds' => []])->assertForbidden();
        $this->actingAs($biller)->postJson(self::API.'/work-areas', ['name' => 'Box'])->assertForbidden();
    }

    public function test_a_worker_is_a_user_who_can_be_assigned_and_sees_only_their_own_orders(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $setup = $this->carwashSetup($company);
        $other = $this->carwashSetup($company, 'PBB2222');

        // Only users whose role can be assigned are workers (Administrador is not, by itself).
        $this->actingAs($owner)->getJson(self::API.'/washers')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['publicId' => $owner->public_id]);
        $mine = $this->postJson(self::API.'/work-orders', $this->orderBody($setup, ['washerPublicId' => $setup['washer']->public_id]))->assertCreated()->json('data.publicId');
        $theirs = $this->postJson(self::API.'/work-orders', $this->orderBody($other, ['washerPublicId' => $other['washer']->public_id]))->assertCreated()->json('data.publicId');
        $this->postJson(self::API.'/work-orders', $this->orderBody($setup, ['washerPublicId' => $owner->public_id]))->assertUnprocessable()->assertJsonPath('error.code', 'CARWASH_WASHER_NOT_FOUND');

        $this->actingAs($setup['washer'])->getJson(self::API.'/work-orders')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.publicId', $mine);
        $this->actingAs($setup['washer'])->getJson(self::API.'/work-orders/'.$mine)->assertOk();
        $this->actingAs($setup['washer'])->getJson(self::API.'/work-orders/'.$theirs)->assertNotFound();
        $this->actingAs($setup['washer'])->getJson(self::API.'/agenda')->assertForbidden();
        $this->actingAs($setup['washer'])->postJson(self::API.'/work-orders', $this->orderBody($setup))->assertForbidden();
    }

    public function test_records_of_another_company_answer_not_found(): void
    {
        [$other] = $this->carwashCompany('1798888888001', ready: false);
        $foreign = $this->carwashSetup($other, 'ABC1234');
        [, $owner] = $this->carwashCompany('1797777777001', ready: false);

        $this->actingAs($owner)->getJson(self::API.'/vehicles/'.$foreign['vehicle']->public_id)->assertNotFound();
        $this->actingAs($owner)->getJson(self::API.'/services/'.$foreign['service']->public_id)->assertNotFound();
        $this->actingAs($owner)->getJson(self::API.'/vehicles/lookup?plate=ABC-1234')->assertNotFound()->assertJsonPath('error.code', 'CARWASH_VEHICLE_NOT_FOUND');
        $this->actingAs($owner)->postJson(self::API.'/vehicles', [
            'plate' => 'XYZ1234', 'customerPublicId' => $foreign['customer']->public_id, 'vehicleTypePublicId' => $foreign['type']->public_id,
        ])->assertNotFound();
    }
}
