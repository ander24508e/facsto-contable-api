<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CompanySetupStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'state' => $this['state'],
            'ready' => $this['ready'],
            'canIssueDocuments' => $this['canIssueDocuments'],
            'issuerReady' => $this['issuerReady'],
            'emissionReady' => $this['emissionReady'],
            'certificateReady' => $this['certificateReady'],
            'onboardingRequired' => $this['onboardingRequired'],
        ];
    }
}
