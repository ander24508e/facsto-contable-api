<?php

namespace App\Domains\Dashboard\Services;

use App\Domains\Catalog\Enums\ItemType;
use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Services\CompanySettings;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Enums\DocumentType;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Sales\Enums\SaleStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** API-owned aggregates for the Dashboard; all queries are scoped to the resolved Company. */
final class CompanyDashboardSummary
{
    public function __construct(private readonly CompanySettings $settings) {}

    /** @return array<string, mixed> */
    public function for(Company $company, string $period): array
    {
        $timezone = (string) config('sri.timezone', 'America/Guayaquil');
        $start = CarbonImmutable::createFromFormat('Y-m-d', $period.'-01', $timezone)->startOfDay();
        $end = $start->addMonth();
        $customerGrowth = [];
        for ($day = $start; $day < $end; $day = $day->addDay()) {
            $customerGrowth[$day->toDateString()] = [
                'date' => $day->toDateString(),
                'newCustomers' => 0,
                'cumulativeCustomers' => 0,
            ];
        }

        // Group in the company's fiscal timezone so registrations near midnight land on the right day.
        $createdAt = $company->customers()
            ->where('created_at', '>=', $start->utc())
            ->where('created_at', '<', $end->utc())
            ->orderBy('created_at')
            ->pluck('created_at');
        foreach ($createdAt as $timestamp) {
            $localDate = CarbonImmutable::parse((string) $timestamp, 'UTC')->setTimezone($timezone)->toDateString();
            if (isset($customerGrowth[$localDate])) {
                $customerGrowth[$localDate]['newCustomers']++;
            }
        }
        $cumulative = $company->customers()
            ->where('created_at', '<', $start->utc())
            ->count();
        foreach ($customerGrowth as &$day) {
            $cumulative += $day['newCustomers'];
            $day['cumulativeCustomers'] = $cumulative;
        }
        unset($day);

        $invoiceQuery = ElectronicDocument::query()
            ->where('company_id', $company->id)
            ->where('document_type', DocumentType::INVOICE->value)
            ->whereDate('issue_date', '>=', $start->toDateString())
            ->whereDate('issue_date', '<', $end->toDateString());
        $invoiceStates = [
            'authorized' => (clone $invoiceQuery)->where('status', DocumentStatus::AUTHORIZED->value)->count(),
            'draft' => $company->sales()
                ->where('status', SaleStatus::DRAFT->value)
                ->whereDate('issue_date', '>=', $start->toDateString())
                ->whereDate('issue_date', '<', $end->toDateString())
                ->count(),
            'errors' => (clone $invoiceQuery)->whereIn('status', [
                DocumentStatus::RETURNED->value,
                DocumentStatus::NOT_AUTHORIZED->value,
                DocumentStatus::FAILED->value,
            ])->count(),
        ];
        $invoiceStates['total'] = $invoiceStates['authorized'] + $invoiceStates['draft'] + $invoiceStates['errors'];

        $stockByProduct = DB::table('items')
            ->leftJoin('item_stocks', 'item_stocks.item_id', '=', 'items.id')
            ->where('items.company_id', $company->id)
            ->where('items.type', ItemType::PRODUCT->value)
            ->where('items.is_active', true)
            ->groupBy('items.id')
            ->selectRaw('items.id as item_id, COALESCE(SUM(item_stocks.quantity), 0) as quantity');
        $threshold = $this->settings->lowStockThreshold($company);
        $inventoryQuery = DB::query()->fromSub($stockByProduct, 'product_stock');
        $inventory = [
            'stockTotal' => bcadd((string) (clone $inventoryQuery)->sum('quantity'), '0', 6),
            'totalTrackedItems' => (clone $inventoryQuery)->count(),
            'normalStockItems' => (clone $inventoryQuery)->where('quantity', '>', $threshold)->count(),
            'lowStockItems' => (clone $inventoryQuery)->where('quantity', '>', 0)->where('quantity', '<=', $threshold)->count(),
            'outOfStockItems' => (clone $inventoryQuery)->where('quantity', '<=', 0)->count(),
            'lowStockThreshold' => $threshold,
        ];

        return [
            'period' => $period,
            'counts' => [
                'customers' => $company->customers()->count(),
                'activeCustomers' => $company->customers()->where('is_active', true)->count(),
                'items' => $company->items()->count(),
                'activeItems' => $company->items()->where('is_active', true)->count(),
                'draftSales' => $company->sales()->where('status', SaleStatus::DRAFT->value)->count(),
            ],
            'customerGrowth' => array_values($customerGrowth),
            'invoiceStates' => $invoiceStates,
            'inventory' => $inventory,
            'recentSales' => $company->sales()->with(['customer', 'creator'])->latest()->limit(5)->get(),
        ];
    }
}
