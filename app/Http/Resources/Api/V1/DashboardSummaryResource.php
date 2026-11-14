<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DashboardSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'counts' => $this['counts'],
            'period' => $this['period'],
            'customerGrowth' => $this['customerGrowth'],
            'invoiceStates' => $this['invoiceStates'],
            'inventory' => $this['inventory'],
            'recentSales' => SaleResource::collection($this['recentSales']),
        ];
    }
}
