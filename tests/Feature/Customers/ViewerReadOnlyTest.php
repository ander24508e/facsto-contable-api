<?php

namespace Tests\Feature\Customers;

use App\Domains\Companies\Actions\AddCompanyMemberAction;
use App\Domains\Companies\Enums\CompanyRole;
use App\Models\User;
use Tests\Feature\Companies\CompanyTestCase;

final class ViewerReadOnlyTest extends CompanyTestCase
{
    public function test_viewer_can_list_customers_but_cannot_create_them(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        app(AddCompanyMemberAction::class)->execute($company, $owner, $viewer, CompanyRole::VIEWER);

        $this->actingAs($viewer)->getJson('/api/v1/customers')->assertOk();
        $this->postJson('/api/v1/customers', ['identificationType' => 'CEDULA', 'identification' => '0123456789', 'legalName' => 'Cliente'])
            ->assertForbidden();
    }
}
