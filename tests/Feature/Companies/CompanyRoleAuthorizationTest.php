<?php

namespace Tests\Feature\Companies;

use App\Domains\Companies\Actions\AddCompanyMemberAction;
use App\Domains\Companies\Enums\CompanyRole;
use App\Models\User;

final class CompanyRoleAuthorizationTest extends CompanyTestCase
{
    public function test_viewer_can_read_company_structure_but_cannot_change_it(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        app(AddCompanyMemberAction::class)->execute($company, $owner, $viewer, CompanyRole::VIEWER);

        $this->actingAs($viewer)->getJson('/api/v1/establishments')->assertOk();
        $this->postJson('/api/v1/establishments', ['code' => '002', 'name' => 'Sucursal', 'address' => 'Guayaquil'])->assertForbidden();
    }
}
