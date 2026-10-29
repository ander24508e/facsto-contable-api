<?php

namespace Tests\Feature\Customers;

use App\Models\User;
use Tests\Feature\Sales\SalesTestCase;

final class DeleteCustomerTest extends SalesTestCase
{
    public function test_a_customer_without_sales_is_deleted(): void
    {
        $owner = User::factory()->create();
        $this->createCompany($owner, '1799999999001');
        $customer = $this->actingAs($owner)->postJson('/api/v1/customers', ['identificationType' => 'CEDULA', 'identification' => '0123456782', 'legalName' => 'Cliente'])
            ->assertCreated()->json('data');

        $this->deleteJson('/api/v1/customers/'.$customer['publicId'])->assertNoContent();

        $this->assertDatabaseMissing('customers', ['public_id' => $customer['publicId']]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.deleted', 'resource_public_id' => $customer['publicId']]);
    }

    public function test_a_customer_with_sales_is_kept_and_only_deactivation_is_offered(): void
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner, '1799999999001');
        $sale = $this->makeSale($owner, $company);

        $this->deleteJson('/api/v1/customers/'.$sale->customer->public_id)
            ->assertStatus(409)->assertJsonPath('error.code', 'CUSTOMER_IN_USE');
        $this->assertDatabaseHas('customers', ['id' => $sale->customer_id, 'is_active' => true]);
    }

    public function test_deleting_does_not_reach_another_company(): void
    {
        $owner = User::factory()->create();
        $this->createCompany($owner, '1799999999001');
        $customer = $this->actingAs($owner)->postJson('/api/v1/customers', ['identificationType' => 'CEDULA', 'identification' => '0123456782', 'legalName' => 'Cliente'])
            ->assertCreated()->json('data');
        $stranger = User::factory()->create();
        $this->createCompany($stranger, '1790016919001');

        $this->actingAs($stranger)->deleteJson('/api/v1/customers/'.$customer['publicId'])->assertForbidden();
        $this->assertDatabaseHas('customers', ['public_id' => $customer['publicId']]);
    }
}
