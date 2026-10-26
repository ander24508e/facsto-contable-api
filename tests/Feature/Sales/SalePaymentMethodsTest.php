<?php

namespace Tests\Feature\Sales;

use App\Models\User;

final class SalePaymentMethodsTest extends SalesTestCase
{
    public function test_payment_method_name_and_code_are_snapshotted(): void
    {
        $user = User::factory()->create();
        $company = $this->createCompany($user, '1799999999001');
        $sale = $this->makeSale($user, $company);
        $method = $sale->paymentMethods()->firstOrFail();
        $this->assertSame('01', $method->method_code);
        $this->assertSame('SIN UTILIZACION DEL SISTEMA FINANCIERO', $method->method_name);
        $this->assertSame('10.00', $method->amount);
    }
}
