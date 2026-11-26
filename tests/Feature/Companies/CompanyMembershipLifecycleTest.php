<?php

namespace Tests\Feature\Companies;

use App\Domains\Companies\Actions\AddCompanyMemberAction;
use App\Domains\Companies\Actions\ReactivateCompanyMemberAction;
use App\Domains\Companies\Actions\RemoveCompanyMemberAction;
use App\Domains\Companies\Actions\UpdateCompanyMemberRolesAction;
use App\Domains\Companies\Models\CompanyMembership;
use App\Models\User;
use App\Shared\Http\ApiException;

final class CompanyMembershipLifecycleTest extends CompanyTestCase
{
    public function test_removed_membership_is_retained_and_can_be_reactivated_with_other_roles(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $membership = app(AddCompanyMemberAction::class)->execute($company, $owner, $member, $this->roleIds($company, 'BILLER'));

        app(RemoveCompanyMemberAction::class)->execute($company, $membership, $owner);
        $this->assertDatabaseHas('company_memberships', ['id' => $membership->id, 'is_active' => false]);

        $explicitlyReactivated = app(ReactivateCompanyMemberAction::class)->execute($company, $membership, $owner);
        $this->assertTrue($explicitlyReactivated->is_active);
        app(RemoveCompanyMemberAction::class)->execute($company, $explicitlyReactivated, $owner);

        $reactivated = app(AddCompanyMemberAction::class)->execute($company, $owner, $member, $this->roleIds($company, 'VIEWER'));
        $this->assertSame($membership->id, $reactivated->id);
        $this->assertTrue($reactivated->is_active);
        $this->assertSame(['Viewer'], $reactivated->roles->pluck('name')->all());
        $this->assertDatabaseCount('company_memberships', 2);
    }

    public function test_only_an_administrator_gives_takes_or_changes_the_administrator_role(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        // A user who manages users but is not Administrador.
        $manager = $this->memberAs($company, ['users.view', 'users.manage']);
        $ownerMembership = CompanyMembership::query()->where('company_id', $company->id)->where('user_id', $owner->id)->firstOrFail();

        $operations = [
            fn () => app(AddCompanyMemberAction::class)->execute($company, $manager, User::factory()->create(), $this->roleIds($company, 'ADMIN')),
            fn () => app(RemoveCompanyMemberAction::class)->execute($company, $ownerMembership, $manager),
            fn () => app(UpdateCompanyMemberRolesAction::class)->execute($company, $ownerMembership, $manager, $this->roleIds($company, 'BILLER')),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('Sólo un Administrador cambia el rol Administrador.');
            } catch (ApiException $exception) {
                $this->assertSame('COMPANY_ACCESS_DENIED', $exception->errorCode->value);
            }
        }

        $this->assertTrue($ownerMembership->fresh()->is_active);
        $this->assertTrue($ownerMembership->fresh()->isAdministrator());
    }

    public function test_the_company_keeps_at_least_one_active_administrator(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $ownerMembership = CompanyMembership::query()->where('company_id', $company->id)->where('user_id', $owner->id)->firstOrFail();

        foreach ([
            fn () => app(RemoveCompanyMemberAction::class)->execute($company, $ownerMembership, $owner),
            fn () => app(UpdateCompanyMemberRolesAction::class)->execute($company, $ownerMembership, $owner, $this->roleIds($company, 'VIEWER')),
        ] as $operation) {
            try {
                $operation();
                $this->fail('La empresa debe conservar un Administrador activo.');
            } catch (ApiException $exception) {
                $this->assertSame('LAST_ADMINISTRATOR_REQUIRED', $exception->errorCode->value);
            }
        }

        // With a second Administrador the first one can leave.
        $second = User::factory()->create();
        app(AddCompanyMemberAction::class)->execute($company, $owner, $second, $this->roleIds($company, 'ADMIN'));
        app(RemoveCompanyMemberAction::class)->execute($company, $ownerMembership, $second);
        $this->assertFalse($ownerMembership->fresh()->is_active);
    }

    public function test_a_user_manager_changes_the_roles_of_other_users(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $manager = $this->memberAs($company, ['users.view', 'users.manage']);
        $membership = app(AddCompanyMemberAction::class)->execute($company, $manager, $member, $this->roleIds($company, 'BILLER'));

        $roles = $this->roleIds($company, 'VIEWER');
        $updated = app(UpdateCompanyMemberRolesAction::class)->execute($company, $membership, $manager, $roles);
        $this->assertSame(['Viewer'], $updated->roles->pluck('name')->all());
        app(RemoveCompanyMemberAction::class)->execute($company, $updated, $manager);
        $this->assertFalse($updated->fresh()->is_active);
    }
}
