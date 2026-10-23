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
            'recentSales' => SaleResource::collection($this['recentSales']),
        ];
    }
}
