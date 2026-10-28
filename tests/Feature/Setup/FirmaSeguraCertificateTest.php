<?php

namespace Tests\Feature\Setup;

use App\Domains\Companies\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * FirmaSegura S.A.S. stores the holder RUC in 1.3.6.1.4.1.61305.3.11 and the cédula in
 * 1.3.6.1.4.1.61305.3.1 (confirmed with a real persona natural certificate). Uses the default config.
 */
final class FirmaSeguraCertificateTest extends SetupTestCase
{
    private const RUC = '1710034065001';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    public function test_a_firmasegura_certificate_signs_with_its_explicit_ruc(): void
    {
        [$owner] = $this->company(self::RUC);

        $this->upload($owner, $this->pkcs12Upload(null, extensions: [
            '1.3.6.1.4.1.61305.3.1' => '1710034065',
            '1.3.6.1.4.1.61305.3.11' => self::RUC,
        ]))->assertCreated()
            ->assertJsonPath('data.status', 'VALID')
            ->assertJsonPath('data.subjectRuc', self::RUC)
            ->assertJsonPath('data.identificationSource', 'extension:1.3.6.1.4.1.61305.3.11');
    }

    public function test_a_firmasegura_certificate_with_only_the_cedula_signs_for_a_natural_person(): void
    {
        [$owner] = $this->company(self::RUC);

        $this->upload($owner, $this->pkcs12Upload(null, extensions: ['1.3.6.1.4.1.61305.3.1' => '1710034065']))
            ->assertCreated()
            ->assertJsonPath('data.subjectRuc', self::RUC)
            ->assertJsonPath('data.identificationSource', 'extension:1.3.6.1.4.1.61305.3.1');
    }

    public function test_a_firmasegura_certificate_of_another_ruc_is_rejected(): void
    {
        [$owner] = $this->company(self::RUC);

        $this->upload($owner, $this->pkcs12Upload(null, extensions: ['1.3.6.1.4.1.61305.3.11' => '1790016919001']))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'CERTIFICATE_RUC_MISMATCH')
            ->assertJsonPath('error.details.certificateRuc', '1790016919001');
    }

    /** @return array{User, Company} */
    private function company(string $ruc): array
    {
        $owner = User::factory()->create();
        $company = $this->createCompany($owner);
        $this->configureIssuer($company, $ruc);
        $this->configureEmission($company);

        return [$owner, $company->refresh()];
    }

    private function upload(User $owner, $file)
    {
        return $this->actingAs($owner)->post('/api/v1/setup/certificate', ['certificate' => $file, 'password' => 'test-password'], ['Accept' => 'application/json']);
    }
}
