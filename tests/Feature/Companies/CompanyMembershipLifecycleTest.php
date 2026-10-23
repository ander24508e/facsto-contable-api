<?php

namespace Tests\Feature\Companies;

use App\Domains\Companies\Actions\AddCompanyMemberAction;
use App\Domains\Companies\Actions\ReactivateCompanyMemberAction;
use App\Domains\Companies\Actions\RemoveCompanyMemberAction;
use App\Domains\Companies\Actions\UpdateCompanyMemberRoleAction;
use App\Domains\Companies\Enums\CompanyRole;
use App\Domains\Companies\Models\CompanyMembership;
use App\Models\User;
use App\Shared\Http\ApiException;

final class CompanyMembershipLifecycleTest extends CompanyTestCase
{
    public function test_removed_membership_is_retained_and_can_be_reactivated(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $membership = app(AddCompanyMemberAction::class)->execute($company, $owner, $member, CompanyRole::BILLER);

        app(RemoveCompanyMemberAction::class)->execute($company, $membership, $owner);
        $this->assertDatabaseHas('company_memberships', ['id' => $membership->id, 'is_active' => false]);

        $explicitlyReactivated = app(ReactivateCompanyMemberAction::class)->execute($company, $membership, $owner);
        $this->assertTrue($explicitlyReactivated->is_active);
        app(RemoveCompanyMemberAction::class)->execute($company, $explicitlyReactivated, $owner);

        $reactivated = app(AddCompanyMemberAction::class)->execute($company, $owner, $member, CompanyRole::VIEWER);
        $this->assertSame($membership->id, $reactivated->id);
        $this->assertTrue($reactivated->is_active);
        $this->assertSame(CompanyRole::VIEWER, $reactivated->role);
        $this->assertDatabaseCount('company_memberships', 2);
    }

    public function test_admin_cannot_create_remove_or_demote_an_owner(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        app(AddCompanyMemberAction::class)->execute($company, $owner, $admin, CompanyRole::ADMIN);
        $ownerMembership = CompanyMembership::query()->where('company_id', $company->id)->where('user_id', $owner->id)->firstOrFail();
        $secondOwner = User::factory()->create();
        $secondOwnerMembership = app(AddCompanyMemberAction::class)->execute($company, $owner, $secondOwner, CompanyRole::OWNER);
        app(RemoveCompanyMemberAction::class)->execute($company, $secondOwnerMembership, $owner);

        $operations = [
            fn () => app(AddCompanyMemberAction::class)->execute($company, $admin, User::factory()->create(), CompanyRole::OWNER),
            fn () => app(RemoveCompanyMemberAction::class)->execute($company, $ownerMembership, $admin),
            fn () => app(UpdateCompanyMemberRoleAction::class)->execute($company, $ownerMembership, $admin, CompanyRole::BILLER),
            fn () => app(AddCompanyMemberAction::class)->execute($company, $admin, $secondOwner, CompanyRole::VIEWER),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('Un ADMIN no debe administrar un OWNER.');
            } catch (ApiException $exception) {
                $this->assertSame('COMPANY_ACCESS_DENIED', $exception->errorCode->value);
            }
        }

        $this->assertTrue($ownerMembership->fresh()->is_active);
        $this->assertSame(CompanyRole::OWNER, $ownerMembership->fresh()->role);
    }

    public function test_admin_can_manage_biller_and_viewer_roles(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        app(AddCompanyMemberAction::class)->execute($company, $owner, $admin, CompanyRole::ADMIN);
        $membership = app(AddCompanyMemberAction::class)->execute($company, $admin, $member, CompanyRole::BILLER);

        $updated = app(UpdateCompanyMemberRoleAction::class)->execute($company, $membership, $admin, CompanyRole::VIEWER);
        $this->assertSame(CompanyRole::VIEWER, $updated->role);
        app(RemoveCompanyMemberAction::class)->execute($company, $updated, $admin);
        $this->assertFalse($updated->fresh()->is_active);
    }
}
