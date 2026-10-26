<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FiscalProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'accountingRequired' => (bool) $this->accounting_required,
            'specialTaxpayerNumber' => $this->special_taxpayer_number,
            'withholdingAgentNumber' => $this->withholding_agent_number,
            'rimpeType' => $this->rimpe_type?->value,
        ];
    }
}
