<?php

namespace Tests\Feature\Setup;

use App\Domains\Companies\Actions\CreateCompanyAction;
use App\Domains\Companies\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

abstract class SetupTestCase extends TestCase
{
    use RefreshDatabase;

    protected function createCompany(User $owner): Company
    {
        return app(CreateCompanyAction::class)->execute($owner, []);
    }

    protected function companyHeader(Company $company): array
    {
        return ['X-Facsto-Company' => $company->public_id];
    }

    protected function configureIssuer(Company $company): void
    {
        $this->actingAs($company->memberships()->first()->user)
            ->withHeaders($this->companyHeader($company))
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
            ->withHeaders($this->companyHeader($company))
            ->putJson('/api/v1/setup/emission', [
                'establishmentCode' => '001',
                'establishmentName' => 'Matriz',
                'establishmentAddress' => 'Quito, Ecuador',
                'emissionPointCode' => '001',
                'emissionPointName' => 'Principal',
            ])->assertOk();
    }

    protected function validPkcs12Upload(string $password = 'test-password'): UploadedFile
    {
        $privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $request = openssl_csr_new(['commonName' => 'FACSTO Test Certificate'], $privateKey, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($request, null, $privateKey, 30, ['digest_alg' => 'sha256']);
        openssl_pkcs12_export($certificate, $p12Contents, $privateKey, $password);

        return UploadedFile::fake()->createWithContent('facsto-test.p12', $p12Contents);
    }
}
