<?php

namespace Tests\Feature\Customers;

use App\Models\User;
use Tests\Feature\Companies\CompanyTestCase;

final class CustomerIsolationTest extends CompanyTestCase
{
    public function test_company_cannot_read_or_modify_another_company_customer(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $companyA = $this->createCompany($userA, '1799999999001');
        $companyB = $this->createCompany($userB, '1799999999002');
        $customer = $this->actingAs($userA)->withHeaders($this->companyHeader($companyA))->postJson('/api/v1/customers', [
            'identificationType' => 'CEDULA', 'identification' => '0123456789', 'legalName' => 'Cliente A',
        ])->assertCreated()->json('data');

        $this->actingAs($userB)->withHeaders($this->companyHeader($companyB))->getJson('/api/v1/customers/'.$customer['publicId'])->assertForbidden();
        $this->patchJson('/api/v1/customers/'.$customer['publicId'], ['legalName' => 'Modificado'])->assertForbidden();
    }
}
