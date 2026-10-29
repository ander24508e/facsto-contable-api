<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Dashboard\Services\FiscalDashboard;
use App\Domains\Sales\Models\Sale;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Dashboard: today's fiscal state, the IVA of a month and the actions pending for the user. */
final class FiscalDashboardController extends Controller
{
    public function status(CompanyContext $context, FiscalDashboard $dashboard): JsonResponse
    {
        $this->authorize('viewAny', Sale::class);

        return response()->json(['data' => $dashboard->status($context->company())]);
    }

    public function taxSummary(Request $request, CompanyContext $context, FiscalDashboard $dashboard): JsonResponse
    {
        $this->authorize('viewAny', Sale::class);
        $period = $request->validate(['period' => ['sometimes', 'date_format:Y-m']])['period'] ?? now((string) config('sri.timezone'))->format('Y-m');

        return response()->json(['data' => $dashboard->taxSummary($context->company(), $period)]);
    }

    public function actions(CompanyContext $context, FiscalDashboard $dashboard): JsonResponse
    {
        $this->authorize('viewAny', Sale::class);

        return response()->json(['data' => $dashboard->actions($context->company())]);
    }
}
