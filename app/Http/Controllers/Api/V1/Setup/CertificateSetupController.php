<?php

namespace App\Http\Controllers\Api\V1\Setup;

use App\Domains\Certificates\Actions\DeactivateDigitalCertificateAction;
use App\Domains\Certificates\Actions\UploadDigitalCertificateAction;
use App\Domains\Certificates\Models\DigitalCertificate;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Fiscal\Actions\CompleteOnboardingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Setup\UploadCertificateRequest;
use App\Http\Resources\Api\V1\DigitalCertificateResource;

final class CertificateSetupController extends Controller
{
    public function store(
        UploadCertificateRequest $request,
        CompanyContext $context,
        UploadDigitalCertificateAction $upload,
        CompleteOnboardingAction $completeOnboarding,
    ): DigitalCertificateResource {
        $this->authorize('update', $context->company());
        $certificate = $upload->execute($request->file('certificate'), $request->string('password')->toString(), $request->user());
        $completeOnboarding->completeIfReady($context->company()->refresh(), $request->user());

        return new DigitalCertificateResource($certificate);
    }

    public function destroy(DigitalCertificate $certificate, CompanyContext $context, DeactivateDigitalCertificateAction $deactivate): DigitalCertificateResource
    {
        $this->authorize('update', $context->company());

        return new DigitalCertificateResource($deactivate->execute($certificate, request()->user()));
    }
}
