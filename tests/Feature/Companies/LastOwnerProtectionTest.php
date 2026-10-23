<?php

namespace Tests\Feature\Companies;

use App\Domains\Companies\Actions\RemoveCompanyMemberAction;
use App\Domains\Companies\Models\CompanyMembership;
use App\Models\User;
use App\Shared\Http\ApiException;

final class LastOwnerProtectionTest extends CompanyTestCase
{
    public function test_last_active_owner_cannot_be_removed(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $membership = CompanyMembership::query()->where('company_id', $company->id)->firstOrFail();

        try {
            app(RemoveCompanyMemberAction::class)->execute($company, $membership, $owner);
            $this->fail('Se esperaba proteger al último owner activo.');
        } catch (ApiException $exception) {
            $this->assertSame('LAST_OWNER_REQUIRED', $exception->errorCode->value);
        }

        $this->assertDatabaseHas('company_memberships', ['id' => $membership->id, 'is_active' => true]);
    }
}
