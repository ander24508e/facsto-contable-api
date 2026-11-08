<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Credit\Services\CreditPaymentRecorder;
use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Models\Sale;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cuentas por cobrar: the invoiced sales on CREDITO, their installments and what has been collected.
 * A collection enters the cash register of the sale.
 */
final class ReceivableController extends Controller
{
    public function index(Request $request, CompanyContext $context): JsonResponse
    {
        $this->authorize('receivables.view');
        $query = $this->withBalance($this->receivables($context))
            ->with(['electronicDocument', 'paymentMethods'])
            ->orderByDesc('issue_date')->orderByDesc('number');
        if ($search = trim((string) $request->query('search'))) {
            $pattern = '%'.mb_strtolower($search).'%';
            $query->where(fn (Builder $builder) => $builder->whereRaw('LOWER(customer_name) LIKE ?', [$pattern])
                ->orWhere('customer_identification', 'like', $search.'%')
                ->orWhereHas('electronicDocument', fn (Builder $document) => $document->where('full_number', 'like', '%'.$search.'%')));
        }
        $this->filterStatus($query, $request->query('status'));
        $page = $query->paginate(min(max((int) $request->query('per_page', 15), 1), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (Sale $sale): array => $this->present($sale)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(CompanyContext $context, string $sale): JsonResponse
    {
        $this->authorize('receivables.view');

        return response()->json(['data' => $this->detail($this->find($context, $sale))]);
    }

    /** POST /receivables/{sale}/payments: collects installments into the sale's cash register (it must be open). */
    public function collect(Request $request, CompanyContext $context, CreditPaymentRecorder $recorder, string $sale): JsonResponse
    {
        $this->authorize('receivables.create');
        $data = $request->validate([
            'amount' => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $recorder->collect($this->find($context, $sale), bcadd($data['amount'], '0', 2), $data['notes'] ?? null, $request->user());

        return response()->json(['data' => $this->detail($this->find($context, $sale))], 201);
    }

    /** Applies the status filter shared by both lists: PENDING, OVERDUE or PAID. */
    public static function filterStatus(Builder $query, mixed $status): void
    {
        $unpaid = fn ($installment) => $installment->whereColumn('paid_amount', '<', 'amount');
        match ($status) {
            'PENDING' => $query->whereHas('installments', $unpaid),
            'OVERDUE' => $query->whereHas('installments', fn ($installment) => $unpaid($installment)->whereDate('due_date', '<', today())),
            'PAID' => $query->whereDoesntHave('installments', $unpaid),
            default => null,
        };
    }

    /** Sums of the installments and the next unpaid due date, for sales and purchases alike. */
    public static function withBalance(Builder $query): Builder
    {
        return $query->withSum('installments as installments_total', 'amount')
            ->withSum('installments as installments_paid', 'paid_amount')
            ->withMin(['installments as next_due_date' => fn ($installment) => $installment->whereColumn('paid_amount', '<', 'amount')], 'due_date');
    }

    /** @return array{paid: string, balance: string, nextDueDate: ?string, status: string} */
    public static function balance(object $owner): array
    {
        $balance = bcsub((string) ($owner->installments_total ?? '0'), (string) ($owner->installments_paid ?? '0'), 2);
        $next = $owner->next_due_date ? substr((string) $owner->next_due_date, 0, 10) : null;

        return [
            'paid' => bcadd((string) ($owner->installments_paid ?? '0'), '0', 2),
            'balance' => $balance,
            'nextDueDate' => $next,
            'status' => bccomp($balance, '0', 2) <= 0 ? 'PAID' : ($next !== null && $next < today()->toDateString() ? 'OVERDUE' : 'PENDING'),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function installments(iterable $installments): array
    {
        return collect($installments)->map(fn ($installment): array => [
            'number' => $installment->number, 'dueDate' => $installment->due_date->toDateString(),
            'amount' => $installment->amount, 'paidAmount' => $installment->paid_amount,
            'balance' => bcsub((string) $installment->amount, (string) $installment->paid_amount, 2),
        ])->values()->all();
    }

    private function receivables(CompanyContext $context): Builder
    {
        return Sale::query()->where('company_id', $context->company()->id)
            ->where('status', SaleStatus::DOCUMENT_PREPARED->value)->whereHas('installments');
    }

    private function find(CompanyContext $context, string $publicId): Sale
    {
        return $this->withBalance($this->receivables($context))->where('public_id', $publicId)
            ->with(['electronicDocument', 'paymentMethods', 'installments', 'creditPayments.creator', 'creditPayments.cashMovement.session.emissionPoint.establishment', 'cashSession.emissionPoint.establishment'])
            ->first() ?? throw new ApiException(ApiErrorCode::RECEIVABLE_NOT_FOUND, 'La cuenta por cobrar no existe en la empresa actual.', 404);
    }

    private function present(Sale $sale): array
    {
        $credit = $sale->paymentMethods->where('is_credit', true);

        return [
            'publicId' => $sale->public_id,
            'saleNumber' => $sale->displayNumber(),
            'invoiceNumber' => $sale->electronicDocument?->full_number,
            'issueDate' => $sale->issue_date?->toDateString(),
            'customer' => ['identification' => $sale->customer_identification, 'legalName' => $sale->customer_name],
            'total' => $sale->total,
            'creditAmount' => bcadd((string) $credit->sum('amount'), '0', 2),
            'downPayment' => bcadd((string) $credit->sum('down_payment'), '0', 2),
            ...self::balance($sale),
        ];
    }

    private function detail(Sale $sale): array
    {
        $point = $sale->cashSession?->emissionPoint;
        $credit = $sale->paymentMethods->firstWhere('is_credit', true);

        return [
            ...$this->present($sale),
            'cashRegister' => $point ? $point->establishment->code.'-'.$point->code : null,
            'installmentFrequency' => $credit?->installment_frequency,
            'creditNotes' => $credit?->credit_notes,
            'installments' => self::installments($sale->installments),
            'payments' => $sale->creditPayments->map(function ($payment): array {
                $paymentPoint = $payment->cashMovement?->session?->emissionPoint;

                return [
                    'publicId' => $payment->public_id, 'amount' => $payment->amount, 'notes' => $payment->notes,
                    'createdAt' => $payment->created_at?->toIso8601String(), 'createdBy' => $payment->creator?->name,
                    'cashRegister' => $paymentPoint ? $paymentPoint->establishment->code.'-'.$paymentPoint->code : null,
                ];
            })->values(),
        ];
    }
}
