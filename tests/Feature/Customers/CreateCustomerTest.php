<?php

namespace Tests\Feature\Customers;

use App\Models\User;
use Tests\Feature\Companies\CompanyTestCase;

final class CreateCustomerTest extends CompanyTestCase
{
    public function test_member_can_create_a_company_customer(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $this->actingAs($owner)->postJson('/api/v1/customers', [
            'identificationType' => 'CEDULA', 'identification' => '0123456789', 'legalName' => 'Cliente de prueba',
        ])->assertCreated()->assertJsonPath('data.identificationType', 'CEDULA');
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'actor_id' => $owner->id, 'action' => 'customer.created']);
    }

    public function test_invalid_identification_format_is_rejected(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $this->actingAs($owner)->postJson('/api/v1/customers', [
            'identificationType' => 'RUC', 'identification' => '123', 'legalName' => 'Cliente de prueba',
        ])->assertUnprocessable();
    }
}
