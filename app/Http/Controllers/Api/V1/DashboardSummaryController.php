<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Models\Sale;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DashboardSummaryResource;

final class DashboardSummaryController extends Controller
{
    public function __invoke(CompanyContext $context): DashboardSummaryResource
    {
        $company = $context->company();
        $this->authorize('viewAny', Sale::class);

        return new DashboardSummaryResource([
            'counts' => [
                'customers' => $company->customers()->count(),
                'activeCustomers' => $company->customers()->where('is_active', true)->count(),
                'items' => $company->items()->count(),
                'activeItems' => $company->items()->where('is_active', true)->count(),
                'draftSales' => $company->sales()->where('status', SaleStatus::DRAFT->value)->count(),
            ],
            'recentSales' => $company->sales()->with(['customer', 'creator'])
                ->latest()->limit(5)->get(),
        ]);
    }
}
