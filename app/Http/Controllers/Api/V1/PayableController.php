<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Credit\Services\CreditPaymentRecorder;
use App\Domains\Purchases\Models\Purchase;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Obligaciones por pagar: purchases on CREDITO, their installments and amounts paid to suppliers. */
final class PayableController extends Controller
{
    public function index(Request $request, CompanyContext $context): JsonResponse
    {
        $this->authorize('payables.view');
        $query = ReceivableController::withBalance($this->supplierObligations($context))->with('supplier')
            ->orderByDesc('issue_date')->orderByDesc('number');
        if ($search = trim((string) $request->query('search'))) {
            $query->whereHas('supplier', fn (Builder $supplier) => $supplier->where('identification', 'like', $search.'%')
                ->orWhereRaw('LOWER(legal_name) LIKE ?', ['%'.mb_strtolower($search).'%']));
        }
        ReceivableController::filterStatus($query, $request->query('status'));
        $page = $query->paginate(min(max((int) $request->query('per_page', 15), 1), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (Purchase $purchase): array => $this->present($purchase)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(CompanyContext $context, string $purchase): JsonResponse
    {
        $this->authorize('payables.view');

        return response()->json(['data' => $this->detail($this->find($context, $purchase))]);
    }

    /** POST /payables/{purchase}/payments: what is paid to the supplier, covering installments in order. */
    public function pay(Request $request, CompanyContext $context, CreditPaymentRecorder $recorder, string $purchase): JsonResponse
    {
        $this->authorize('payables.create');
        $data = $request->validate([
            'amount' => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $recorder->pay($this->find($context, $purchase), bcadd($data['amount'], '0', 2), $data['notes'] ?? null, $request->user());

        return response()->json(['data' => $this->detail($this->find($context, $purchase))], 201);
    }

    private function supplierObligations(CompanyContext $context): Builder
    {
        return Purchase::query()->where('company_id', $context->company()->id)->where('is_credit', true)->whereHas('installments');
    }

    private function find(CompanyContext $context, string $publicId): Purchase
    {
        return ReceivableController::withBalance($this->supplierObligations($context))->where('public_id', $publicId)
            ->with(['supplier', 'installments', 'payments.creator'])
            ->first() ?? throw new ApiException(ApiErrorCode::PURCHASE_NOT_FOUND, 'La cuenta por pagar no existe en la empresa actual.', 404);
    }

    private function present(Purchase $purchase): array
    {
        return [
            'publicId' => $purchase->public_id,
            'number' => $purchase->displayNumber(),
            'supplierDocumentNumber' => $purchase->supplier_document_number,
            'issueDate' => $purchase->issue_date->toDateString(),
            'supplier' => ['identification' => $purchase->supplier?->identification, 'legalName' => $purchase->supplier?->legal_name],
            'total' => $purchase->total,
            'creditAmount' => $purchase->total,
            'downPayment' => $purchase->down_payment,
            ...ReceivableController::balance($purchase),
        ];
    }

    private function detail(Purchase $purchase): array
    {
        return [
            ...$this->present($purchase),
            'installmentFrequency' => $purchase->installment_frequency,
            'creditNotes' => $purchase->credit_notes,
            'installments' => ReceivableController::installments($purchase->installments),
            'payments' => $purchase->payments->map(fn ($payment): array => [
                'publicId' => $payment->public_id, 'amount' => $payment->amount, 'notes' => $payment->notes,
                'createdAt' => $payment->created_at?->toIso8601String(), 'createdBy' => $payment->creator?->name,
            ])->values(),
        ];
    }
}
