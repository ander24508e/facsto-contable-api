<?php

namespace App\Domains\Dashboard\Services;

use App\Domains\Catalog\Models\Item;
use App\Domains\Companies\Fiscal\Services\ElectronicInvoicingRequirements;
use App\Domains\Companies\Models\Company;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Enums\DocumentType;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Sales\Enums\SaleStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard reads only what already exists: document states, authorized totals per tax rate and the
 * situations that need the user's action. Every figure links to the screen where it is resolved.
 */
final class FiscalDashboard
{
    private const IN_PROCESS = ['NUMBERED', 'XML_BUILT', 'SIGNED', 'QUEUED', 'RECEIVED', 'PENDING_AUTHORIZATION'];

    public function __construct(private readonly ElectronicInvoicingRequirements $requirements) {}

    /** Today's documents by bucket; FAILED ones are still retried until 23:59:59 of their issue date. */
    public function status(Company $company): array
    {
        $timezone = (string) config('sri.timezone', 'America/Guayaquil');
        $today = CarbonImmutable::now($timezone)->toDateString();
        $documents = ElectronicDocument::query()->where('company_id', $company->id);
        $counts = (clone $documents)->whereDate('issue_date', $today)->groupBy('status')->selectRaw('status, COUNT(*) as total')->pluck('total', 'status');
        $count = fn (array $statuses): int => (int) collect($statuses)->sum(fn (string $status): int => (int) ($counts[$status] ?? 0));

        $monthStart = CarbonImmutable::now($timezone)->startOfMonth()->toDateString();

        return [
            'date' => $today,
            'authorized' => $count(['AUTHORIZED']),
            'inProcess' => $count(self::IN_PROCESS),
            'retrying' => $count(['FAILED']),
            // Problems are not only today's: a returned invoice stays pending until it is corrected.
            'problems' => (clone $documents)->whereIn('status', [DocumentStatus::RETURNED->value, DocumentStatus::NOT_AUTHORIZED->value])->count()
                + (clone $documents)->where('status', DocumentStatus::FAILED->value)->whereDate('issue_date', '<', $today)->count(),
            'retryDeadline' => CarbonImmutable::parse($today.' 23:59:59', $timezone)->toIso8601String(),
            'usage' => [
                'documentsThisMonth' => (clone $documents)->whereDate('issue_date', '>=', $monthStart)->count(),
                // Comes from the Platform entitlements (F09); until then there is no limit.
                'limit' => null,
            ],
        ];
    }

    /**
     * For the dashboard charts: authorized invoiced and credited totals per day of a month, and the
     * documents of the last 7 days per bucket (same buckets as the fiscal status).
     *
     * @return array<string, mixed>
     */
    public function trend(Company $company, string $period): array
    {
        $timezone = (string) config('sri.timezone', 'America/Guayaquil');
        $start = CarbonImmutable::createFromFormat('Y-m-d', $period.'-01', $timezone)->startOfDay();
        $end = $start->endOfMonth();

        $totals = ElectronicDocument::query()->where('company_id', $company->id)
            ->where('status', DocumentStatus::AUTHORIZED->value)
            ->whereDate('issue_date', '>=', $start->toDateString())
            ->whereDate('issue_date', '<=', $end->toDateString())
            ->groupBy('issue_date', 'document_type')
            ->selectRaw('issue_date, document_type, SUM(total) as amount')
            ->get();
        $days = [];
        for ($day = $start; $day <= $end; $day = $day->addDay()) {
            $days[$day->toDateString()] = ['date' => $day->toDateString(), 'invoiced' => '0.00', 'credited' => '0.00'];
        }
        foreach ($totals as $row) {
            // SQLite returns the date with a time part; PostgreSQL without it.
            $date = substr((string) $row->getRawOriginal('issue_date'), 0, 10);
            $key = $row->getRawOriginal('document_type') === DocumentType::CREDIT_NOTE->value ? 'credited' : 'invoiced';
            $days[$date][$key] = bcadd($days[$date][$key], (string) $row->getRawOriginal('amount'), 2);
        }

        $today = CarbonImmutable::now($timezone)->startOfDay();
        $weekStart = $today->subDays(6);
        $counts = ElectronicDocument::query()->where('company_id', $company->id)
            ->whereDate('issue_date', '>=', $weekStart->toDateString())
            ->groupBy('issue_date', 'status')
            ->selectRaw('issue_date, status, COUNT(*) as total')
            ->get();
        $week = [];
        for ($day = $weekStart; $day <= $today; $day = $day->addDay()) {
            $week[$day->toDateString()] = ['date' => $day->toDateString(), 'authorized' => 0, 'inProcess' => 0, 'retrying' => 0, 'problems' => 0];
        }
        foreach ($counts as $row) {
            $date = substr((string) $row->getRawOriginal('issue_date'), 0, 10);
            $status = (string) $row->getRawOriginal('status');
            $bucket = match (true) {
                $status === DocumentStatus::AUTHORIZED->value => 'authorized',
                in_array($status, self::IN_PROCESS, true) => 'inProcess',
                $status === DocumentStatus::FAILED->value => 'retrying',
                in_array($status, [DocumentStatus::RETURNED->value, DocumentStatus::NOT_AUTHORIZED->value], true) => 'problems',
                default => null,
            };
            if ($bucket !== null && isset($week[$date])) {
                $week[$date][$bucket] += (int) $row->getRawOriginal('total');
            }
        }

        return ['period' => $period, 'days' => array_values($days), 'lastSevenDays' => array_values($week)];
    }

    /**
     * Authorized sales of a month for the IVA return: per rate, invoices minus credit notes.
     *
     * @return array<string, mixed>
     */
    public function taxSummary(Company $company, string $period): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $period.'-01')->startOfDay();
        $authorized = fn () => ElectronicDocument::query()
            ->where('company_id', $company->id)
            ->where('status', DocumentStatus::AUTHORIZED->value)
            ->whereDate('issue_date', '>=', $start->toDateString())
            ->whereDate('issue_date', '<=', $start->endOfMonth()->toDateString());

        $rates = DB::table('electronic_document_taxes')
            ->join('electronic_documents', 'electronic_documents.id', '=', 'electronic_document_taxes.electronic_document_id')
            ->whereIn('electronic_documents.id', $authorized()->select('id'))
            ->groupBy('electronic_document_taxes.tax_code', 'electronic_document_taxes.percentage_code', 'electronic_document_taxes.percentage', 'electronic_documents.document_type')
            ->selectRaw('electronic_document_taxes.tax_code, electronic_document_taxes.percentage_code, electronic_document_taxes.percentage, electronic_documents.document_type, SUM(electronic_document_taxes.taxable_base) as base, SUM(electronic_document_taxes.tax_amount) as amount')
            ->get();

        $summary = [];
        foreach ($rates as $rate) {
            $key = $rate->tax_code.'|'.$rate->percentage_code;
            $summary[$key] ??= [
                'taxCode' => $rate->tax_code, 'percentageCode' => $rate->percentage_code,
                'percentage' => bcadd((string) $rate->percentage, '0', 2),
                'salesBase' => '0.00', 'salesTax' => '0.00', 'creditBase' => '0.00', 'creditTax' => '0.00',
            ];
            $prefix = $rate->document_type === DocumentType::CREDIT_NOTE->value ? 'credit' : 'sales';
            $summary[$key][$prefix.'Base'] = bcadd($summary[$key][$prefix.'Base'], (string) $rate->base, 2);
            $summary[$key][$prefix.'Tax'] = bcadd($summary[$key][$prefix.'Tax'], (string) $rate->amount, 2);
        }
        foreach ($summary as &$row) {
            $row['netBase'] = bcsub($row['salesBase'], $row['creditBase'], 2);
            $row['netTax'] = bcsub($row['salesTax'], $row['creditTax'], 2);
        }
        unset($row);
        $total = fn (DocumentType $type): string => bcadd((string) ($authorized()->where('document_type', $type->value)->sum('total') ?: '0'), '0', 2);

        return [
            'period' => $period,
            'invoiceCount' => $authorized()->where('document_type', DocumentType::INVOICE->value)->count(),
            'creditNoteCount' => $authorized()->where('document_type', DocumentType::CREDIT_NOTE->value)->count(),
            'invoicedTotal' => $total(DocumentType::INVOICE),
            'creditedTotal' => $total(DocumentType::CREDIT_NOTE),
            'rates' => array_values($summary),
        ];
    }

    /**
     * Only what needs the user now; each card disappears once resolved.
     *
     * @return list<array{code: string, count: ?int, date: ?string, to: string}>
     */
    public function actions(Company $company): array
    {
        $actions = [];
        $certificate = $company->digitalCertificates()->where('is_active', true)->first();
        $warningDays = (int) config('sri.certificate_expiry_warning_days', 30);
        if ($certificate && $certificate->valid_until->lte(now()->addDays($warningDays))) {
            $actions[] = ['code' => 'CERTIFICATE_EXPIRING', 'count' => null, 'date' => $certificate->valid_until->toDateString(), 'to' => '/companies'];
        }
        $problems = ElectronicDocument::query()->where('company_id', $company->id)
            ->whereIn('status', [DocumentStatus::RETURNED->value, DocumentStatus::NOT_AUTHORIZED->value])->count();
        if ($problems > 0) {
            $actions[] = ['code' => 'DOCUMENTS_RETURNED', 'count' => $problems, 'date' => null, 'to' => '/sales?stage=problems'];
        }
        $pending = $this->requirements->pending($company);
        if ($pending !== []) {
            $actions[] = ['code' => 'ELECTRONIC_INVOICING_INCOMPLETE', 'count' => count($pending), 'date' => null, 'to' => '/companies'];
        } elseif (! $company->fiscalSettings?->sri_submission_enabled) {
            $actions[] = ['code' => 'ELECTRONIC_INVOICING_OFF', 'count' => null, 'date' => null, 'to' => '/companies'];
        }
        $lowStock = $company->items()->where('type', 'PRODUCT')->where('is_active', true)
            ->whereHas('stocks', fn ($query) => $query->where('quantity', '<=', Item::LOW_STOCK))->count();
        if ($lowStock > 0) {
            $actions[] = ['code' => 'LOW_STOCK', 'count' => $lowStock, 'date' => null, 'to' => '/catalog/items?stock=low'];
        }
        $staleDrafts = $company->sales()->where('status', SaleStatus::DRAFT->value)->where('updated_at', '<', now()->subDays(7))->count();
        if ($staleDrafts > 0) {
            $actions[] = ['code' => 'STALE_DRAFTS', 'count' => $staleDrafts, 'date' => null, 'to' => '/sales?stage=draft'];
        }

        return $actions;
    }
}
