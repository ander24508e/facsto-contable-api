<?php

namespace Tests\Feature\Sri;

use App\Domains\Certificates\DTOs\CertificateMaterial;
use App\Domains\FiscalDocuments\Enums\DocumentArtifactType;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Sri\Signing\Contracts\XmlSigner;
use App\Domains\Sri\Signing\JavaXadesBesSigner;
use App\Domains\Sri\Signing\SignatureValidator;
use App\Domains\Sri\Signing\XmlSigningException;
use App\Domains\Sri\Xml\XsdValidator;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Sales\InvoiceFeatureTestCase;

/** F05.3: NUMBERED → XML_BUILT → SIGNED with the real Java signer and an independent PHP verification. */
final class SignElectronicDocumentTest extends InvoiceFeatureTestCase
{
    public function test_issue_signs_with_xades_bes_and_the_signature_verifies_independently(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->makeSale($user, $company);

        $data = $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/issue')->assertCreated()
            ->assertJsonPath('data.status', 'SIGNED')->json('data');
        $invoice = ElectronicDocument::query()->where('public_id', $data['publicId'])->firstOrFail();
        $this->assertNotNull($invoice->numbered_at);
        $this->assertNotNull($invoice->xml_built_at);
        $this->assertNotNull($invoice->signed_at);
        $this->assertNotNull($data['timeline']['signedAt']);

        $signed = $invoice->artifacts()->where('type', DocumentArtifactType::SIGNED_XML->value)->firstOrFail();
        $signedXml = Storage::disk('private')->get($signed->path);
        $this->assertSame(hash('sha256', $signedXml), $signed->sha256);
        $this->assertStringContainsString('<claveAcceso>'.$invoice->access_key.'</claveAcceso>', $signedXml);
        $this->assertStringContainsString('xmlns:etsi="http://uri.etsi.org/01903/v1.3.2#"', $signedXml);

        // Independent checks: certificate of the company, cryptography and the official XSD.
        app(SignatureValidator::class)->validate($signedXml, $this->certificateDer($company->ruc));
        app(XsdValidator::class)->validate($signedXml, (string) config('sri.invoice.xsd_path'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'invoice.signed', 'company_id' => $company->id]);
    }

    public function test_a_signature_that_does_not_verify_never_produces_a_signed_document(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->makeSale($user, $company);
        $this->app->bind(XmlSigner::class, fn () => new class implements XmlSigner
        {
            public function sign(string $unsignedXml, CertificateMaterial $material): string
            {
                $signed = (new JavaXadesBesSigner)->sign($unsignedXml, $material);

                return (string) preg_replace('#<razonSocial>[^<]*</razonSocial>#', '<razonSocial>EMISOR ALTERADO</razonSocial>', $signed, 1);
            }
        });

        $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/issue')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'INVOICE_SIGNATURE_INVALID')
            ->assertJsonPath('error.details.reason_code', 'DIGEST_MISMATCH')
            ->assertJsonPath('error.details.invoiceStatus', 'XML_BUILT');

        $invoice = ElectronicDocument::query()->where('sale_id', $sale->id)->firstOrFail();
        $this->assertSame(DocumentStatus::XML_BUILT, $invoice->status);
        $this->assertSame('DIGEST_MISMATCH', $invoice->last_error_code);
        $this->assertSame(0, $invoice->artifacts()->where('type', DocumentArtifactType::SIGNED_XML->value)->count());
        $this->assertNull($invoice->signed_at);
    }

    public function test_the_java_signer_rejects_a_wrong_password_without_exposing_it(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'facsto-test-p12-');
        file_put_contents($path, $this->pkcs12Contents('1790016919001'));
        $der = $this->certificateDer('1790016919001');

        try {
            (new JavaXadesBesSigner)->sign('<factura id="comprobante" version="1.1.0"></factura>', new CertificateMaterial($path, 'otra-clave', $der));
            $this->fail('The signer accepted a wrong password.');
        } catch (XmlSigningException $exception) {
            $this->assertSame('KEYSTORE_UNREADABLE', $exception->reasonCode);
            $this->assertStringNotContainsString('otra-clave', $exception->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function test_a_tampered_signed_artifact_is_never_served(): void
    {
        $user = User::factory()->create();
        $company = $this->readyCompany($user);
        $sale = $this->makeSale($user, $company);
        $data = $this->actingAs($user)->postJson('/api/v1/sales/'.$sale->public_id.'/issue')->assertCreated()->json('data');
        $invoice = ElectronicDocument::query()->where('public_id', $data['publicId'])->firstOrFail();

        $base = '/api/v1/invoices/'.$invoice->public_id.'/artifacts';
        $this->actingAs($user)->getJson($base)->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($user)->get($base.'/signed-xml')->assertOk()->assertHeader('Content-Type', 'application/xml');
        $this->actingAs($user)->getJson($base.'/RIDE')->assertNotFound()->assertJsonPath('error.code', 'DOCUMENT_ARTIFACT_NOT_FOUND');

        $signed = $invoice->artifacts()->where('type', DocumentArtifactType::SIGNED_XML->value)->firstOrFail();
        Storage::disk('private')->put($signed->path, Storage::disk('private')->get($signed->path).' ');
        $this->actingAs($user)->getJson($base.'/SIGNED_XML')->assertConflict()->assertJsonPath('error.code', 'DOCUMENT_ARTIFACT_INTEGRITY_FAILED');
    }

    private function certificateDer(string $ruc): string
    {
        openssl_pkcs12_read($this->pkcs12Contents($ruc), $parts, 'test-password');
        openssl_x509_export($parts['cert'], $pem);

        return base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $pem));
    }
}
