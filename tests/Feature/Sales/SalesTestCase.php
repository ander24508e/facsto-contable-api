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
use Tests\Support\CreatesCompanyTaxes;
use Tests\Support\CreatesTestCertificates;

abstract class SalesTestCase extends CompanyTestCase
{
    use CreatesCompanyTaxes;
    use CreatesTestCertificates;

    protected function setUp(): void
    {
        parent::setUp();
        // Certificates and XML artifacts never touch the real private disk.
        Storage::fake('private');
        $this->seedSriCatalog();
    }

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
        // Every invoiceable line needs exactly one IVA tax; 0 % keeps the helper totals unchanged.
        $item->taxes()->attach($this->companyIva($company, '0')->id, ['company_id' => $company->id]);
        $body = [
            'customerPublicId' => $customer->public_id,
            'lines' => [['itemPublicId' => $item->public_id, 'quantity' => '1', 'discountAmount' => '0.00']],
            'paymentMethods' => $withPayment ? [['fiscalPaymentMethodPublicId' => $this->paymentMethod()->public_id, 'amount' => '10.00']] : [],
        ];
        $response = $this->actingAs($user)->postJson('/api/v1/sales', $body)->assertCreated();

        return Sale::query()->where('public_id', $response->json('data.publicId'))->firstOrFail();
    }

    /** Official payment method 01 (tabla 24), loaded by the catalog seeder. */
    protected function paymentMethod(): FiscalPaymentMethod
    {
        return FiscalPaymentMethod::query()->where('code', '01')->firstOrFail();
    }

    protected function makeCompanyReady(Company $company): void
    {
        $company->fiscalSettings()->create(['accounting_required' => false, 'sri_environment' => 'TEST', 'emission_type' => 'NORMAL']);
        $establishment = $company->establishments()->create(['code' => '001', 'name' => 'Matriz', 'address' => 'Quito', 'is_active' => true, 'is_main' => true]);
        $establishment->emissionPoints()->create(['company_id' => $company->id, 'code' => '001', 'name' => 'Principal', 'is_active' => true, 'is_default' => true]);
        $this->installReadyCertificate($company);
    }
}
