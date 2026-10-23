<?php

namespace Tests\Feature\Sales;

use App\Models\User;

final class SaleCustomerSnapshotTest extends SalesTestCase
{
    public function test_customer_values_are_snapshotted_on_sale(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $sale = $this->makeSale($user, $company);
        $sale->customer->forceFill(['legal_name' => 'Nombre futuro'])->save();
        $this->assertSame('Cliente de prueba', $sale->fresh()->customer_name);
    }
}
