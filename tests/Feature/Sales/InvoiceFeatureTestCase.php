<?php

namespace Tests\Feature\Sales;

use App\Domains\Companies\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

abstract class InvoiceFeatureTestCase extends SalesTestCase
{
    protected function readyCompany(User $user): Company
    {
        $company = $this->createCompany($user, '1799999999001');
        $company->forceFill(['ruc' => '1790016919001', 'legal_name' => 'Emisor válido', 'main_address' => 'Quito'])->save();
        Storage::disk('private')->put('tests/certificate.p12', 'test certificate placeholder');
        $company->fiscalSettings()->create(['accounting_required' => false, 'sri_environment' => 'TEST', 'emission_type' => 'NORMAL']);
        $establishment = $company->establishments()->create(['code' => '001', 'name' => 'Matriz', 'address' => 'Quito', 'is_active' => true, 'is_main' => true]);
        $establishment->emissionPoints()->create(['company_id' => $company->id, 'code' => '001', 'name' => 'Principal', 'is_active' => true, 'is_default' => true]);
        $company->digitalCertificates()->create([
            'storage_path' => 'tests/certificate.p12', 'password_ciphertext' => encrypt('test'), 'issued_to' => 'FACSTO test',
            'issuer' => 'Test issuer', 'serial_number' => 'TEST-1', 'valid_from' => now()->subDay(), 'valid_until' => now()->addYear(),
            'status' => 'VALID', 'validated_at' => now(), 'last_checked_at' => now(), 'is_active' => true,
        ]);

        return $company;
    }
}
