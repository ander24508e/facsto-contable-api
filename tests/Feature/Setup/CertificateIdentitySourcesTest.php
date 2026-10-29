<?php

namespace Tests\Feature\Setup;

use App\Domains\Companies\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * A persona natural signs with a certificate that may only carry the holder's cédula: RUC = cédula + 001.
 * The OID below is a test-only source; real sources are added to config only once confirmed.
 */
final class CertificateIdentitySourcesTest extends SetupTestCase
{
    private const TEST_CEDULA_OID = '1.3.6.1.4.1.99999.1.1';

    private const NATURAL_PERSON_RUC = '1710034065001';

    private const CEDULA = '1710034065';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        config(['sri.certificate_identification.cedula_sources' => ['extension:'.self::TEST_CEDULA_OID]]);
    }

    public function test_a_natural_person_certificate_with_only_the_cedula_signs_for_its_ruc(): void
    {
        [$owner, $company] = $this->company(self::NATURAL_PERSON_RUC);

        $this->upload($owner, $this->pkcs12Upload(null, extensions: [self::TEST_CEDULA_OID => self::CEDULA]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'VALID')
            ->assertJsonPath('data.subjectRuc', self::NATURAL_PERSON_RUC)
            ->assertJsonPath('data.identificationSource', 'extension:'.self::TEST_CEDULA_OID);
        $status = $this->actingAs($owner)->getJson('/api/v1/setup/status')->assertJsonPath('data.canIssueDocuments', true);
        $this->assertNotNull($status->json('data.environment'));
        $this->assertNotNull($status->json('data.certificateValidUntil'));
        $this->assertDatabaseHas('digital_certificates', ['company_id' => $company->id, 'is_active' => true, 'subject_ruc' => self::NATURAL_PERSON_RUC]);
    }

    public function test_a_cedula_never_signs_for_a_sociedad(): void
    {
        // Sociedad RUC (third digit 9): the cédula of a legal representative does not identify the company.
        [$owner] = $this->company('1790016919001');

        $this->upload($owner, $this->pkcs12Upload(null, extensions: [self::TEST_CEDULA_OID => self::CEDULA]))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'CERTIFICATE_RUC_MISMATCH');
    }

    public function test_another_persons_cedula_is_a_mismatch(): void
    {
        [$owner] = $this->company(self::NATURAL_PERSON_RUC);

        $this->upload($owner, $this->pkcs12Upload(null, extensions: [self::TEST_CEDULA_OID => '1720127180']))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'CERTIFICATE_RUC_MISMATCH')
            ->assertJsonPath('error.details.certificateRuc', '1720127180001');
    }

    public function test_an_unrecognized_source_is_rejected_but_recorded_for_diagnosis(): void
    {
        [$owner, $company] = $this->company(self::NATURAL_PERSON_RUC);
        $unknown = '1.3.6.1.4.1.88888.2.3';

        $response = $this->upload($owner, $this->pkcs12Upload(null, extensions: [$unknown => self::CEDULA]))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'CERTIFICATE_RUC_NOT_FOUND')
            ->assertJsonPath('error.details.identificationSources', ['extension:'.$unknown]);

        $certificate = $company->digitalCertificates()->where('public_id', $response->json('error.details.certificatePublicId'))->firstOrFail();
        $this->assertSame([['source' => 'extension:'.$unknown, 'value' => self::CEDULA, 'context' => self::CEDULA]], $certificate->identification_candidates);
        $this->assertNull($certificate->storage_path);
        $this->assertNull($certificate->password_ciphertext);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_an_invalid_cedula_check_digit_is_ignored(): void
    {
        [$owner] = $this->company(self::NATURAL_PERSON_RUC);

        $this->upload($owner, $this->pkcs12Upload(null, extensions: [self::TEST_CEDULA_OID => '1710034066']))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'CERTIFICATE_RUC_NOT_FOUND');
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
