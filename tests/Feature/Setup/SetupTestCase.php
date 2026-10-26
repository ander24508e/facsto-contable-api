<?php

namespace Tests\Feature\Setup;

use App\Domains\Companies\Actions\CreateCompanyAction;
use App\Domains\Companies\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\CreatesTestCertificates;
use Tests\TestCase;

abstract class SetupTestCase extends TestCase
{
    use CreatesTestCertificates;
    use RefreshDatabase;

    protected function createCompany(User $owner): Company
    {
        return app(CreateCompanyAction::class)->execute($owner, ['slug' => 'empresa-'.$owner->id]);
    }

    protected function configureIssuer(Company $company): void
    {
        $this->actingAs($company->memberships()->first()->user)
            ->putJson('/api/v1/setup/issuer', [
                'ruc' => '1790016919001',
                'legalName' => 'Emisor de prueba',
                'commercialName' => 'FACSTO Test',
                'mainAddress' => 'Quito, Ecuador',
            ])->assertOk();
    }

    protected function configureEmission(Company $company): void
    {
        $this->actingAs($company->memberships()->first()->user)
            ->putJson('/api/v1/setup/emission', [
                'establishmentCode' => '001',
                'establishmentName' => 'Matriz',
                'establishmentAddress' => 'Quito, Ecuador',
                'emissionPointCode' => '001',
                'emissionPointName' => 'Principal',
            ])->assertOk();
    }

    /** A valid certificate whose RUC extension matches the issuer configured by configureIssuer(). */
    protected function validPkcs12Upload(string $password = 'test-password'): UploadedFile
    {
        return $this->pkcs12Upload('1790016919001', $password);
    }
}
