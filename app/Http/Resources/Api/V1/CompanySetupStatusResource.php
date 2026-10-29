<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

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
            'environment' => $this['environment'],
            'certificateValidUntil' => $this['certificateValidUntil'] === null ? null : Carbon::parse($this['certificateValidUntil'])->toIso8601String(),
        ];
    }
}
