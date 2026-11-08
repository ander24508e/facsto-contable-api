<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Purchases\Models\Purchase;
use App\Domains\Purchases\Services\PurchaseWriter;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Inversiones: the purchases of the business (list, register and detail). */
final class PurchaseController extends Controller
{
    public function index(Request $request, CompanyContext $context): JsonResponse
    {
        $this->authorize('investments.view');
        $query = Purchase::query()->where('company_id', $context->company()->id)->with('supplier')->withCount('lines')
            ->orderByDesc('issue_date')->orderByDesc('number');
        if ($search = trim((string) $request->query('search'))) {
            $query->whereHas('supplier', fn ($supplier) => $supplier->where('identification', 'like', $search.'%')
                ->orWhereRaw('LOWER(legal_name) LIKE ?', ['%'.mb_strtolower($search).'%']));
        }
        if ($request->query('credit') === '1') {
            $query->where('is_credit', true);
        }
        $page = $query->paginate(min(max((int) $request->query('per_page', 15), 1), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (Purchase $purchase): array => $this->present($purchase)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request, CompanyContext $context, PurchaseWriter $writer): JsonResponse
    {
        $this->authorize('investments.create');
        $money = ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,6})?$/'];
        $data = $request->validate([
            'supplierPublicId' => ['required', 'uuid'],
            'warehousePublicId' => ['required', 'uuid'],
            'issueDate' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'supplierDocumentNumber' => ['nullable', 'string', 'regex:/^\d{3}-\d{3}-\d{9}$/'],
            'supplierAuthorizationNumber' => ['nullable', 'string', 'regex:/^(\d{10}|\d{37}|\d{49})$/'],
            'notes' => ['nullable', 'string', 'max:500'],
            'fiscalPaymentMethodPublicId' => ['required', 'uuid'],
            'downPayment' => ['nullable', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'installmentFrequency' => ['nullable', 'in:DAILY,BIWEEKLY,MONTHLY,SEMIANNUAL,ANNUAL'],
            'creditNotes' => ['nullable', 'string', 'max:500'],
            'installments' => ['nullable', 'array', 'max:120'],
            'installments.*.dueDate' => ['required', 'date_format:Y-m-d'],
            'installments.*.amount' => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.itemPublicId' => ['required_without:lines.*.newItem', 'nullable', 'uuid'],
            'lines.*.newItem' => ['nullable', 'array'],
            'lines.*.newItem.name' => ['required_with:lines.*.newItem', 'string', 'max:60'],
            'lines.*.newItem.internalCode' => ['nullable', 'string', 'max:25'],
            'lines.*.newItem.auxiliaryCode' => ['nullable', 'string', 'max:25'],
            'lines.*.newItem.unit' => ['nullable', 'string', 'max:32'],
            'lines.*.newItem.salePrice' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,16}(\.\d{1,6})?$/'],
            'lines.*.newItem.priceIncludesTax' => ['sometimes', 'boolean'],
            'lines.*.newItem.description' => ['nullable', 'string'],
            'lines.*.newItem.additionalDetail' => ['nullable', 'string', 'max:300'],
            'lines.*.newItem.categoryPublicId' => ['nullable', 'uuid'],
            'lines.*.newItem.brandPublicId' => ['nullable', 'uuid'],
            'lines.*.newItem.barcode' => ['nullable', 'string', 'max:64'],
            'lines.*.newItem.priceTiers' => ['sometimes', 'array', 'max:10'],
            'lines.*.newItem.priceTiers.*.minQuantity' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'lines.*.newItem.priceTiers.*.price' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'lines.*.newItem.taxPublicIds' => ['sometimes', 'array', 'max:8'],
            'lines.*.newItem.taxPublicIds.*' => ['required', 'uuid'],
            'lines.*.newItem.taxPublicId' => ['nullable', 'uuid'],
            'lines.*.quantity' => $money,
            'lines.*.unitCost' => $money,
            'lines.*.taxPublicId' => ['nullable', 'uuid'],
        ]);
        foreach ($data['lines'] as $line) {
            if (bccomp((string) $line['quantity'], '0', 6) <= 0) {
                throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'Cada producto comprado necesita una cantidad mayor a cero.', 422);
            }
            foreach ($line['newItem']['priceTiers'] ?? [] as $tier) {
                if (bccomp((string) $tier['minQuantity'], '1', 6) <= 0) {
                    throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'El precio alterno empieza desde 2 unidades.', 422);
                }
            }
        }

        return response()->json(['data' => $this->present($writer->create($context->company(), $data, $request->user()), true)], 201);
    }

    public function show(CompanyContext $context, Purchase $purchase): JsonResponse
    {
        $this->authorize('investments.view');
        if ($purchase->company_id !== $context->company()->id) {
            throw new ApiException(ApiErrorCode::PURCHASE_NOT_FOUND, 'La compra no existe en la empresa actual.', 404);
        }

        return response()->json(['data' => $this->present($purchase->load(['supplier', 'warehouse', 'creator', 'lines.item', 'installments']), true)]);
    }

    private function present(Purchase $purchase, bool $full = false): array
    {
        $paid = $purchase->relationLoaded('installments') ? (string) $purchase->installments->sum('paid_amount') : null;

        return [
            'publicId' => $purchase->public_id,
            'number' => $purchase->displayNumber(),
            'issueDate' => $purchase->issue_date->toDateString(),
            'supplier' => ['publicId' => $purchase->supplier?->public_id, 'identification' => $purchase->supplier?->identification, 'legalName' => $purchase->supplier?->legal_name],
            'supplierDocumentNumber' => $purchase->supplier_document_number,
            'supplierAuthorizationNumber' => $purchase->supplier_authorization_number,
            'paymentMethod' => ['code' => $purchase->payment_method_code, 'name' => $purchase->payment_method_name, 'isCredit' => $purchase->is_credit],
            'subtotal' => $purchase->subtotal,
            'taxTotal' => $purchase->tax_total,
            'total' => $purchase->total,
            'linesCount' => $purchase->lines_count ?? ($purchase->relationLoaded('lines') ? $purchase->lines->count() : null),
            'createdAt' => $purchase->created_at?->toIso8601String(),
            ...($full ? [
                'notes' => $purchase->notes,
                'warehouse' => ['publicId' => $purchase->warehouse?->public_id, 'name' => $purchase->warehouse?->name],
                'createdBy' => $purchase->creator?->name,
                'lines' => $purchase->lines->map(fn ($line): array => [
                    'itemPublicId' => $line->item?->public_id, 'itemCode' => $line->item_code, 'itemName' => $line->item_name, 'quantity' => $line->quantity, 'unitCost' => $line->unit_cost,
                    'taxPercentage' => $line->tax_percentage, 'subtotal' => $line->subtotal, 'taxTotal' => $line->tax_total, 'total' => $line->total,
                ])->values(),
                'installments' => $purchase->installments->map(fn ($installment): array => [
                    'number' => $installment->number, 'dueDate' => $installment->due_date->toDateString(), 'amount' => $installment->amount, 'paidAmount' => $installment->paid_amount,
                ])->values(),
                'downPayment' => $purchase->down_payment,
                'installmentFrequency' => $purchase->installment_frequency,
                'creditNotes' => $purchase->credit_notes,
                'balance' => bcsub((string) $purchase->installments->sum('amount'), $paid ?? '0', 2),
                'retention' => ($retention = $purchase->retention()->with('withholdings')->first()) ? [
                    'publicId' => $retention->public_id, 'fullNumber' => $retention->full_number, 'status' => $retention->status->value,
                    'withheldTotal' => $retention->withholdings->reduce(fn (string $sum, $row): string => bcadd($sum, (string) $row->amount, 2), '0.00'),
                ] : null,
            ] : []),
        ];
    }
}
