<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Enums\DocumentType;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Models\SalePaymentMethod;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ventas → Transacciones: one row per payment of an invoiced sale (cash, card, transfer with its reference,
 * credit…), with the state of its invoice. «Total cobrado» adds the payments of AUTHORIZED invoices.
 */
final class SaleTransactionController extends Controller
{
    /** Document states grouped as the Transacciones filter shows them. */
    private const STATUS_GROUPS = [
        'authorized' => [DocumentStatus::AUTHORIZED],
        'sent' => [DocumentStatus::NUMBERED, DocumentStatus::XML_BUILT, DocumentStatus::SIGNED, DocumentStatus::QUEUED, DocumentStatus::RECEIVED, DocumentStatus::PENDING_AUTHORIZATION],
        'problems' => [DocumentStatus::RETURNED, DocumentStatus::NOT_AUTHORIZED, DocumentStatus::FAILED],
    ];

    public function index(Request $request, CompanyContext $context): JsonResponse
    {
        $this->authorize('viewAny', Sale::class);
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'method' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', 'nullable', Rule::in(array_keys(self::STATUS_GROUPS))],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->filtered($context->company()->id, $filters);
        $collected = (clone $query)
            ->whereHas('sale.electronicDocument', fn (Builder $document) => $document->where('status', DocumentStatus::AUTHORIZED))
            ->sum('amount');
        $page = $query->with(['sale.electronicDocument', 'fiscalPaymentMethod'])->orderByDesc('id')->paginate($filters['per_page'] ?? 15);

        return response()->json([
            'data' => $page->getCollection()->map(fn (SalePaymentMethod $payment): array => $this->present($payment))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'collectedTotal' => bcadd((string) $collected, '0', 2),
            ],
        ]);
    }

    /** @param array<string, mixed> $filters */
    private function filtered(int $companyId, array $filters): Builder
    {
        $query = SalePaymentMethod::query()->where('company_id', $companyId)
            ->whereHas('sale.electronicDocument', fn (Builder $document) => $document->where('document_type', DocumentType::INVOICE));

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $pattern = '%'.mb_strtolower($search).'%';
            $query->where(fn (Builder $builder) => $builder
                ->whereRaw('LOWER(reference) LIKE ?', [$pattern])
                ->orWhereHas('sale', fn (Builder $sale) => $sale->whereRaw('LOWER(customer_name) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(customer_identification) LIKE ?', [$pattern]))
                ->orWhereHas('sale.electronicDocument', fn (Builder $document) => $document->where('full_number', 'like', '%'.$search.'%')));
        }
        if ($method = $filters['method'] ?? null) {
            $query->whereHas('fiscalPaymentMethod', fn (Builder $builder) => $builder->where('public_id', $method));
        }
        if ($status = $filters['status'] ?? null) {
            $query->whereHas('sale.electronicDocument', fn (Builder $document) => $document->whereIn('status', self::STATUS_GROUPS[$status]));
        }
        if ($from = $filters['from'] ?? null) {
            $query->whereHas('sale.electronicDocument', fn (Builder $document) => $document->whereDate('issue_date', '>=', $from));
        }
        if ($to = $filters['to'] ?? null) {
            $query->whereHas('sale.electronicDocument', fn (Builder $document) => $document->whereDate('issue_date', '<=', $to));
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function present(SalePaymentMethod $payment): array
    {
        $sale = $payment->sale;
        $invoice = $sale?->electronicDocument;

        return [
            'amount' => $payment->amount,
            'methodCode' => $payment->method_code,
            'methodName' => $payment->method_name,
            'isCredit' => (bool) $payment->is_credit,
            'isTransfer' => (bool) $payment->fiscalPaymentMethod?->is_transfer,
            'reference' => $payment->reference,
            'sale' => ['publicId' => $sale?->public_id, 'number' => $sale?->displayNumber()],
            'customer' => ['name' => $sale?->customer_name, 'identification' => $sale?->customer_identification, 'email' => $sale?->customer_email],
            'invoice' => [
                'publicId' => $invoice?->public_id,
                'fullNumber' => $invoice?->full_number,
                'status' => $invoice?->status->value,
                'issueDate' => $invoice?->issue_date?->toDateString(),
            ],
        ];
    }
}
