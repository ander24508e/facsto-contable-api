<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Catalog\Enums\ItemType;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Inventory\Services\StockReservations;
use App\Domains\Proformas\Actions\ConvertProformaToSaleAction;
use App\Domains\Proformas\Actions\DeleteProformaAction;
use App\Domains\Proformas\Actions\ProformaFollowUpAction;
use App\Domains\Proformas\Actions\UpdateProformaAction;
use App\Domains\Proformas\Models\Proforma;
use App\Domains\Proformas\Models\ProformaLine;
use App\Domains\Proformas\Services\ProformaWriter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Proformas\ConvertProformaRequest;
use App\Http\Resources\Api\V1\SaleResource;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Proformas: offers with their own numbering; the same selling permissions as Ventas. */
final class ProformaController extends Controller
{
    private const RELATIONS = ['customer', 'creator', 'warehouse', 'lines.item.stocks', 'advancePaymentMethod'];

    public function index(Request $request, CompanyContext $context): JsonResponse
    {
        $this->authorize('proformas.view');
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', 'in:PENDING,ACCEPTED,REJECTED,CONVERTED,EXPIRED'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $query = Proforma::query()->where('company_id', $context->company()->id)->with(['customer', 'creator', 'convertedSale'])->withCount('lines')->latest()->latest('id');
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $pattern = '%'.mb_strtolower($search).'%';
            $query->where(fn ($builder) => $builder->whereRaw('LOWER(customer_name) LIKE ?', [$pattern])->orWhereRaw('LOWER(customer_identification) LIKE ?', [$pattern]));
        }
        match ($filters['status'] ?? null) {
            null => null,
            'EXPIRED' => $query->whereIn('status', Proforma::OPEN)->whereDate('expiration_date', '<', today()),
            'PENDING', 'ACCEPTED' => $query->where('status', $filters['status'])->whereDate('expiration_date', '>=', today()),
            default => $query->where('status', $filters['status']),
        };
        $page = $query->paginate($filters['per_page'] ?? 15);
        $base = Proforma::query()->where('company_id', $context->company()->id);
        $closed = (clone $base)->whereIn('status', [Proforma::CONVERTED, Proforma::REJECTED])->count();

        return response()->json([
            'data' => $page->getCollection()->map($this->present(...)),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                // How many closed offers became a sale.
                'conversion' => ['converted' => (clone $base)->where('status', Proforma::CONVERTED)->count(), 'closed' => $closed],
            ],
        ]);
    }

    public function accept(Request $request, Proforma $proforma, CompanyContext $context, ProformaFollowUpAction $followUp): JsonResponse
    {
        $this->authorize('proformas.create');
        $this->ensureOwn($proforma, $context);

        return response()->json(['data' => $this->present($followUp->accept($proforma, $request->user())->load(self::RELATIONS), true)]);
    }

    public function reject(Request $request, Proforma $proforma, CompanyContext $context, ProformaFollowUpAction $followUp): JsonResponse
    {
        $this->authorize('proformas.create');
        $this->ensureOwn($proforma, $context);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:2', 'max:120']])['reason'];

        return response()->json(['data' => $this->present($followUp->reject($proforma, $reason, $request->user())->load(self::RELATIONS), true)]);
    }

    public function renew(Request $request, Proforma $proforma, CompanyContext $context, ProformaFollowUpAction $followUp): JsonResponse
    {
        $this->authorize('proformas.create');
        $this->ensureOwn($proforma, $context);
        $days = (int) $request->validate(['days' => ['required', 'integer', 'in:7,15']])['days'];

        return response()->json(['data' => $this->present($followUp->renew($proforma, $days, $request->user())->load(self::RELATIONS), true)]);
    }

    public function store(Request $request, ProformaWriter $writer): JsonResponse
    {
        $this->authorize('proformas.create');

        return response()->json(['data' => $this->present($writer->save(null, $this->validated($request), $request->user()), true)], 201);
    }

    public function show(Proforma $proforma, CompanyContext $context): JsonResponse
    {
        $this->authorize('proformas.view');
        $this->ensureOwn($proforma, $context);

        return response()->json(['data' => $this->present($proforma->load([...self::RELATIONS, 'convertedSale']), true)]);
    }

    public function update(Request $request, Proforma $proforma, UpdateProformaAction $update): JsonResponse
    {
        $this->authorize('proformas.create');

        return response()->json(['data' => $this->present($update->execute($proforma, $this->validated($request), $request->user()), true)]);
    }

    public function destroy(Request $request, Proforma $proforma, DeleteProformaAction $delete): Response
    {
        $this->authorize('proformas.create');
        $delete->execute($proforma, $request->user());

        return response()->noContent();
    }

    public function convert(Proforma $proforma, ConvertProformaRequest $request, ConvertProformaToSaleAction $convert, CompanyContext $context): JsonResponse
    {
        $this->authorize('proformas.create');
        $this->ensureOwn($proforma, $context);

        return (new SaleResource($convert->execute($proforma, $request->validated(), $request->user())))
            ->response()->setStatusCode(201);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'customerPublicId' => ['sometimes', 'nullable', 'uuid'],
            'warehousePublicId' => ['sometimes', 'nullable', 'uuid'],
            'issueDate' => ['required', 'date_format:Y-m-d'],
            'expirationDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:issueDate'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Advance or credit: its products are taken from stock instead of only reserved.
            'advanceAmount' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,14}(\.\d{1,2})?$/'],
            'advancePaymentMethodPublicId' => ['sometimes', 'nullable', 'uuid'],
            'onCredit' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.itemPublicId' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,6})?$/'],
            'lines.*.discountAmount' => ['nullable', 'string', 'regex:/^\d{1,14}(\.\d{1,2})?$/'],
        ]);
    }

    /**
     * Semáforo of each product line: FREE (green, enough free stock), RESERVED (yellow, other proformas hold the
     * units it needs) or OUT (gray, not enough stock). Services have none.
     *
     * @return array<int, array{state: string, stock: string, reserved: string, available: string}>
     */
    private function availability(Proforma $proforma): array
    {
        $products = $proforma->lines->filter(fn (ProformaLine $line) => $line->item?->type === ItemType::PRODUCT);
        $reserved = app(StockReservations::class)->forItems($proforma->company_id, $products->pluck('item_id')->unique()->values()->all(), $proforma->id);
        $result = [];
        foreach ($products->groupBy('item_id') as $itemId => $lines) {
            $wanted = (string) $lines->sum('quantity');
            $stock = bcadd((string) $lines->first()->item->stocks->sum('quantity'), '0', 6);
            $held = $reserved[$itemId] ?? '0';
            $available = bcsub($stock, $held, 6);
            $state = $proforma->stock_committed || bccomp($available, $wanted, 6) >= 0 ? 'FREE' : (bccomp($stock, $wanted, 6) >= 0 ? 'RESERVED' : 'OUT');
            $result[$itemId] = ['state' => $state, 'stock' => $stock, 'reserved' => $held, 'available' => $available];
        }

        return $result;
    }

    private function ensureOwn(Proforma $proforma, CompanyContext $context): void
    {
        if ($proforma->company_id !== $context->company()->id) {
            throw new ApiException(ApiErrorCode::PROFORMA_NOT_FOUND, 'La proforma no existe en la empresa activa.', 404);
        }
    }

    /** @return array<string, mixed> */
    private function present(Proforma $proforma, bool $withLines = false): array
    {
        $taxSummary = [];
        $availability = $withLines ? $this->availability($proforma) : [];
        if ($withLines) {
            foreach ($proforma->lines as $line) {
                foreach ($line->taxes ?? [] as $tax) {
                    $key = $tax['tax_type'].':'.$tax['sri_code'].':'.$tax['percentage_code'];
                    $taxSummary[$key] ??= [
                        'taxType' => $tax['tax_type'],
                        'sriCode' => $tax['sri_code'],
                        'percentageCode' => $tax['percentage_code'],
                        'name' => $tax['tax_name'],
                        'percentage' => $tax['percentage'],
                        'taxableBase' => '0.00',
                        'taxAmount' => '0.00',
                    ];
                    $taxSummary[$key]['taxableBase'] = bcadd($taxSummary[$key]['taxableBase'], $tax['taxable_base'], 2);
                    $taxSummary[$key]['taxAmount'] = bcadd($taxSummary[$key]['taxAmount'], $tax['tax_amount'], 2);
                }
            }
        }

        return [
            'publicId' => $proforma->public_id,
            'number' => $proforma->displayNumber(),
            'customer' => [
                'publicId' => $proforma->customer?->public_id,
                'identificationType' => $proforma->customer_identification_type,
                'identification' => $proforma->customer_identification,
                'name' => $proforma->customer_name,
                'address' => $proforma->customer_address,
                'email' => $proforma->customer_email,
                'phone' => $proforma->customer_phone,
            ],
            'warehousePublicId' => $proforma->warehouse?->public_id,
            'issueDate' => $proforma->issue_date?->toDateString(),
            'expirationDate' => $proforma->expiration_date?->toDateString(),
            // PENDING, ACCEPTED, EXPIRED (open past its date), REJECTED, CONVERTED or CONVERSION_PENDING.
            'status' => $proforma->displayStatus(),
            'rejectionReason' => $proforma->rejection_reason,
            'advanceAmount' => (string) $proforma->advance_amount,
            'advancePaymentMethod' => $proforma->advancePaymentMethod ? ['publicId' => $proforma->advancePaymentMethod->public_id, 'name' => $proforma->advancePaymentMethod->name] : null,
            'onCredit' => $proforma->on_credit,
            // Taken from stock (advance or credit) or only reserved (yellow).
            'stockCommitted' => $proforma->stock_committed,
            'convertedSalePublicId' => $proforma->convertedSale?->public_id,
            'notes' => $proforma->notes,
            'subtotal' => (string) $proforma->subtotal,
            'discountTotal' => (string) $proforma->discount_total,
            'taxTotal' => (string) $proforma->tax_total,
            'total' => (string) $proforma->total,
            'linesCount' => $proforma->lines_count ?? $proforma->lines->count(),
            ...($withLines ? ['taxSummary' => array_values($taxSummary)] : []),
            'createdBy' => $proforma->creator?->name,
            'createdAt' => $proforma->created_at->toIso8601String(),
            'updatedAt' => $proforma->updated_at->toIso8601String(),
            ...($withLines ? ['lines' => $proforma->lines->map(fn (ProformaLine $line): array => [
                'itemPublicId' => $line->item->public_id, 'itemCode' => $line->item_code, 'itemName' => $line->item_name, 'unit' => $line->unit,
                'quantity' => (string) $line->quantity, 'unitPrice' => (string) $line->unit_price, 'discountAmount' => (string) $line->discount_amount,
                'subtotal' => (string) $line->subtotal, 'taxableBase' => (string) $line->taxable_base, 'taxTotal' => (string) $line->tax_total, 'total' => (string) $line->total,
                'taxes' => $line->taxes,
                'availability' => $availability[$line->item_id] ?? null,
            ])->all()] : []),
        ];
    }
}
