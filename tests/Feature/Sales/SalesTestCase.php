<?php

namespace Tests\Feature\Sales;

use App\Domains\Catalog\Models\FiscalPaymentMethod;
use App\Domains\Catalog\Models\Item;
use App\Domains\Companies\Models\Company;
use App\Domains\Customers\Enums\IdentificationType;
use App\Domains\Customers\Models\Customer;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Companies\CompanyTestCase;

abstract class SalesTestCase extends CompanyTestCase
{
    protected function makeSale(User $user, Company $company, bool $withPayment = true): Sale
    {
        $customer = Customer::query()->create([
            'company_id' => $company->id, 'identification_type' => IdentificationType::CEDULA,
            'identification' => '0102030405', 'legal_name' => 'Cliente de prueba', 'is_active' => true,
        ]);
        $item = Item::query()->create([
            'company_id' => $company->id, 'type' => 'SERVICE', 'internal_code' => 'SERV-1',
            'name' => 'Servicio', 'unit' => 'UND', 'sale_price' => '10.000000', 'is_active' => true,
        ]);
        $method = FiscalPaymentMethod::query()->create([
            'code' => 'TEST01', 'name' => 'Método de prueba', 'valid_from' => today()->subDay(), 'is_active' => true,
        ]);
        $body = [
            'customerPublicId' => $customer->public_id,
            'lines' => [['itemPublicId' => $item->public_id, 'quantity' => '1', 'discountAmount' => '0.00']],
            'paymentMethods' => $withPayment ? [['fiscalPaymentMethodPublicId' => $method->public_id, 'amount' => '10.00']] : [],
        ];
        $response = $this->actingAs($user)->withHeaders($this->companyHeader($company))->postJson('/api/v1/sales', $body)->assertCreated();

        return Sale::query()->where('public_id', $response->json('data.publicId'))->firstOrFail();
    }

    protected function makeCompanyReady(Company $company): void
    {
        Storage::disk('private')->put('tests/certificate.p12', 'test certificate placeholder');
        $company->fiscalSettings()->create(['accounting_required' => false, 'sri_environment' => 'TEST', 'emission_type' => 'NORMAL']);
        $establishment = $company->establishments()->create(['code' => '001', 'name' => 'Matriz', 'address' => 'Quito', 'is_active' => true, 'is_main' => true]);
        $establishment->emissionPoints()->create(['company_id' => $company->id, 'code' => '001', 'name' => 'Principal', 'is_active' => true, 'is_default' => true]);
        $company->digitalCertificates()->create([
            'storage_path' => 'tests/certificate.p12', 'password_ciphertext' => encrypt('test'),
            'issued_to' => 'FACSTO test', 'issuer' => 'Test issuer', 'serial_number' => 'TEST-1',
            'valid_from' => now()->subDay(), 'valid_until' => now()->addYear(), 'status' => 'VALID',
            'validated_at' => now(), 'last_checked_at' => now(), 'is_active' => true,
        ]);
    }
}
