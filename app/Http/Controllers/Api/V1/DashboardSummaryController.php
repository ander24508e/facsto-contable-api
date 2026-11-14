<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Dashboard\Services\CompanyDashboardSummary;
use App\Domains\Sales\Models\Sale;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DashboardSummaryResource;
use Illuminate\Http\Request;

final class DashboardSummaryController extends Controller
{
    public function __invoke(Request $request, CompanyContext $context, CompanyDashboardSummary $summary): DashboardSummaryResource
    {
        $company = $context->company();
        $this->authorize('viewAny', Sale::class);
        $period = $request->validate(['period' => ['sometimes', 'date_format:Y-m']])['period']
            ?? now((string) config('sri.timezone', 'America/Guayaquil'))->format('Y-m');

        return new DashboardSummaryResource($summary->for($company, $period));
    }
}
