<?php

namespace Tests\Feature\Customers;

use App\Models\User;
use Tests\Feature\Companies\CompanyTestCase;

final class DeactivateCustomerTest extends CompanyTestCase
{
    public function test_customer_is_deactivated_without_physical_deletion(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $this->actingAs($owner);
        $customer = $this->postJson('/api/v1/customers', ['identificationType' => 'CEDULA', 'identification' => '0123456782', 'legalName' => 'Cliente'])
            ->assertCreated()->json('data');

        $this->postJson('/api/v1/customers/'.$customer['publicId'].'/deactivate')->assertOk()->assertJsonPath('data.isActive', false);
        $this->assertDatabaseHas('customers', ['public_id' => $customer['publicId'], 'is_active' => false]);
    }
}
