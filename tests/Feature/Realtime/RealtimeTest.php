<?php

namespace Tests\Feature\Realtime;

use App\Domains\Modules\Enums\EntitlementStatus;
use App\Domains\Modules\Models\CompanyEntitlement;
use App\Models\User;
use App\Shared\Realtime\Realtime;
use App\Shared\Realtime\ResourceChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Feature\Carwash\CarwashTestCase;

/** Regla 31: private channels per company, module and user; notices only after the commit and without data. */
final class RealtimeTest extends CarwashTestCase
{
    private bool $reverb = false;

    /**
     * The null broadcaster authorizes everything; Reverb's signs only the channels its callback allows. It is turned on
     * after the fixtures (they announce their changes and there is no Reverb server in the tests).
     */
    private function auth(User $user, string $channel): TestResponse
    {
        if (! $this->reverb) {
            $this->reverb = true;
            config([
                'broadcasting.default' => 'reverb',
                'broadcasting.connections.reverb.key' => 'test-key',
                'broadcasting.connections.reverb.secret' => 'test-secret',
                'broadcasting.connections.reverb.app_id' => 'test-app',
            ]);
            // The channels register on the broadcaster in use: again, now on Reverb's.
            require base_path('routes/channels.php');
        }

        return $this->actingAs($user)->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-{$channel}"]);
    }

    public function test_each_member_listens_only_to_its_company_and_itself(): void
    {
        [$company, $owner] = $this->carwashCompany();
        [$other, $stranger] = $this->carwashCompany('1790011223001', ready: false);
        $viewer = $this->member($company, 'VIEWER');

        $this->auth($viewer, "company.{$company->public_id}")->assertOk()->assertJsonStructure(['auth']);
        $this->auth($viewer, "company.{$other->public_id}")->assertForbidden();
        $this->auth($stranger, "company.{$company->public_id}")->assertForbidden();
        $this->auth($viewer, "user.{$viewer->public_id}")->assertOk();
        $this->auth($viewer, "user.{$owner->public_id}")->assertForbidden();
        // Without a session, nobody.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-company.{$company->public_id}"])->assertUnauthorized();
    }

    public function test_a_module_channel_needs_the_module_on_and_one_of_its_permissions(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $channel = "company.{$company->public_id}.module.carwash";
        $seller = $this->memberAs($company, ['sales.view']);

        $this->auth($owner, $channel)->assertOk();
        $this->auth($seller, $channel)->assertForbidden();
        $this->auth($owner, "company.{$company->public_id}.module.unknown")->assertForbidden();
        // Read-only still reads; disabled, nothing.
        CompanyEntitlement::query()->where('company_id', $company->id)->update(['status' => EntitlementStatus::READ_ONLY]);
        $this->app->forgetScopedInstances();
        $this->auth($owner, $channel)->assertOk();
        CompanyEntitlement::query()->where('company_id', $company->id)->update(['status' => EntitlementStatus::DISABLED]);
        $this->app->forgetScopedInstances();
        $this->auth($owner, $channel)->assertForbidden();
    }

    public function test_a_notice_goes_out_only_after_the_commit_and_carries_no_data(): void
    {
        [$company] = $this->carwashCompany();
        $notices = [];
        Event::listen(ResourceChanged::class, function (ResourceChanged $notice) use (&$notices): void {
            $notices[] = $notice;
        });
        $realtime = app(Realtime::class);

        try {
            DB::transaction(function () use ($realtime, $company): void {
                $realtime->changed($company, 'items', 'abc', 'created');
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame([], $notices);

        DB::transaction(fn () => $realtime->changed($company, 'carwash-orders', 'xyz', 'updated', '2026-11-19T10:00:00-05:00', module: 'carwash'));
        $this->assertCount(1, $notices);
        $notice = $notices[0];
        $this->assertSame("private-company.{$company->public_id}.module.carwash", $notice->broadcastOn()->name);
        $this->assertSame('changed', $notice->broadcastAs());
        $this->assertSame(['id', 'resource', 'publicId', 'action', 'version'], array_keys($notice->broadcastWith()));
        $this->assertSame(['carwash-orders', 'xyz', 'updated'], [$notice->resource, $notice->publicId, $notice->action]);
    }

    public function test_saving_a_product_announces_it_and_its_stock_once(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $notices = [];
        Event::listen(ResourceChanged::class, function (ResourceChanged $notice) use (&$notices): void {
            $notices[] = "{$notice->resource}:{$notice->action}";
        });

        $category = $this->actingAs($owner)->postJson('/api/v1/categories', ['name' => 'Ceras'])->assertCreated()->json('data.publicId');
        $notices = [];
        $item = $this->postJson('/api/v1/items', [
            'type' => 'PRODUCT', 'internalCode' => 'RT-1', 'name' => 'Cera', 'unit' => 'UND', 'salePrice' => '5.00',
            'categoryPublicId' => $category, 'stock' => $this->productStock($company, '4'),
        ])->assertCreated()->json('data.publicId');

        // The item, its taxes, its stock row and its first movement: one notice per resource and action.
        $this->assertContains('items:created', $notices);
        $this->assertSame(1, count(array_keys($notices, 'stock:updated', true)));
        $this->assertSame(count($notices), count(array_unique($notices)));
        $this->assertNotNull($item);
    }

    public function test_a_form_with_an_old_version_does_not_overwrite_another_persons_change(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $category = $this->actingAs($owner)->postJson('/api/v1/categories', ['name' => 'Ceras'])->assertCreated()->json('data.publicId');
        $item = $this->postJson('/api/v1/items', [
            'type' => 'SERVICE', 'internalCode' => 'RT-2', 'name' => 'Pulido', 'unit' => 'SERVICIO', 'salePrice' => '5.00', 'categoryPublicId' => $category,
        ])->assertCreated()->json('data');

        // Another person saves it a moment later; the first form still has the old version.
        $this->travel(5)->seconds();
        $this->patchJson("/api/v1/items/{$item['publicId']}", ['name' => 'Pulido fino', 'version' => $item['updatedAt']])->assertOk();
        $this->patchJson("/api/v1/items/{$item['publicId']}", ['name' => 'Pulido simple', 'version' => $item['updatedAt']])
            ->assertConflict()->assertJsonPath('error.code', 'RECORD_CHANGED');
        $this->assertDatabaseHas('items', ['public_id' => $item['publicId'], 'name' => 'Pulido fino']);
        // Without a version (integrations, older screens) it saves as before.
        $this->patchJson("/api/v1/items/{$item['publicId']}", ['name' => 'Pulido simple'])->assertOk();
    }

    public function test_changing_a_role_tells_its_members_to_reload_their_permissions(): void
    {
        [$company] = $this->carwashCompany();
        $seller = $this->memberAs($company, ['sales.view']);
        $role = $company->memberships()->where('user_id', $seller->id)->sole()->roles()->sole();
        $notices = [];
        Event::listen(ResourceChanged::class, function (ResourceChanged $notice) use (&$notices): void {
            $notices[] = $notice->broadcastOn()->name.'|'.$notice->resource;
        });

        // Another request (each one announces a resource once).
        $this->app->forgetScopedInstances();
        $role->syncPermissions(['sales.view', 'sales.create']);

        $this->assertContains("private-user.{$seller->public_id}|access", $notices);
        $this->assertContains("private-company.{$company->public_id}|roles", $notices);
    }

    public function test_a_background_request_does_not_count_as_activity(): void
    {
        [$company, $owner] = $this->carwashCompany();
        $before = now()->subMinutes(5)->getTimestamp();

        // From the web (stateful, with its session).
        $web = ['Referer' => 'http://localhost:5173/', 'Origin' => 'http://localhost:5173'];

        $this->actingAs($owner)->withSession(['facsto.last_activity_at' => $before])
            ->getJson('/api/v1/company', [...$web, 'X-Facsto-Background' => '1'])->assertOk()->assertSessionHas('facsto.last_activity_at', $before);
        $this->actingAs($owner)->withSession(['facsto.last_activity_at' => $before])->getJson('/api/v1/company', $web)->assertOk();
        $this->assertGreaterThan($before, session('facsto.last_activity_at'));
    }
}
