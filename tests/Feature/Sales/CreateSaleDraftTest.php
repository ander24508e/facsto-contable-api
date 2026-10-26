<?php

namespace Tests\Feature\Sales;

use App\Models\User;

final class CreateSaleDraftTest extends SalesTestCase
{
    public function test_member_can_create_a_draft_with_payment_methods_still_empty(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $sale = $this->makeSale($user, $company, false);

        $this->assertSame('DRAFT', $sale->status->value);
        $this->assertDatabaseCount('sale_payment_methods', 0);
    }

    public function test_member_can_save_an_operational_draft_before_selecting_customer_or_lines(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $this->actingAs($user)->postJson('/api/v1/sales', ['notes' => 'Empezar después', 'lines' => [], 'paymentMethods' => []])
            ->assertCreated()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.customer.publicId', null)
            ->assertJsonPath('data.lines', [])->assertJsonPath('data.total', '0.00');
    }
}
