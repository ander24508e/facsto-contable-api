<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Catalog\Enums\ItemType;
use App\Domains\Catalog\Models\Item;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Services\CompanySettings;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Inventory\Services\StockLedger;
use App\Domains\Inventory\Services\StockReservations;
use App\Http\Controllers\Controller;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Stock (Catálogo › Stock): sólo artículos de tipo producto. La lista con lo que hay, lo reservado por proformas
 * (amarillo) y lo disponible; los últimos movimientos del kardex con su documento; un ingreso o egreso a mano con su
 * motivo y el ajuste masivo por conteo físico (AJ-000001).
 */
final class StockController extends Controller
{
    public function index(Request $request, CompanyContext $context, StockReservations $reservations, CompanySettings $settings): JsonResponse
    {
        $this->authorize('stock.view');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'warehousePublicId' => ['nullable', 'uuid'],
            'categoryPublicId' => ['nullable', 'uuid'],
            'state' => ['nullable', 'in:LOW,RESERVED,OUT'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $company = $context->company();
        $threshold = $settings->lowStockThreshold($company);
        $query = $company->items()->where('type', ItemType::PRODUCT->value)->where('is_active', true)
            ->with(['stocks.warehouse', 'category', 'catalogMetadata.brand'])
            ->orderBy('name');
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $pattern = '%'.mb_strtolower($search).'%';
            $query->where(fn ($match) => $match->whereRaw('LOWER(name) LIKE ?', [$pattern])->orWhereRaw('LOWER(internal_code) LIKE ?', [$pattern]));
        }
        $query->when($filters['warehousePublicId'] ?? null, fn ($builder, $warehouse) => $builder->whereHas('stocks.warehouse', fn ($inner) => $inner->where('public_id', $warehouse)));
        $query->when($filters['categoryPublicId'] ?? null, fn ($builder, $category) => $builder->whereHas('category', fn ($inner) => $inner->where('public_id', $category)));
        match ($filters['state'] ?? null) {
            'LOW' => $query->whereHas('stocks', fn ($builder) => $builder->where('quantity', '<=', $threshold)->where('quantity', '>', 0)),
            'OUT' => $query->where(fn ($builder) => $builder->whereDoesntHave('stocks')->orWhereHas('stocks', fn ($inner) => $inner->where('quantity', '<=', 0))),
            'RESERVED' => $query->whereIn('id', array_keys($reservations->forItems($company->id, $company->items()->where('type', ItemType::PRODUCT->value)->pluck('id')->all()))),
            default => null,
        };
        $page = $query->paginate($filters['per_page'] ?? 50);
        $reserved = $reservations->forItems($company->id, $page->getCollection()->pluck('id')->all());
        $last = StockMovement::query()->whereIn('item_id', $page->getCollection()->pluck('id'))
            ->groupBy('item_id')->selectRaw('item_id, MAX(created_at) as at')->pluck('at', 'item_id');

        return response()->json([
            'data' => $page->getCollection()->map(function (Item $item) use ($reserved, $last, $threshold): array {
                $rows = $item->stocks->sortBy('id')->values();
                $stock = $rows->first();
                $quantity = bcadd((string) $rows->sum('quantity'), '0', 6);
                $held = $reserved[$item->id] ?? '0';

                return [
                    'publicId' => $item->public_id,
                    'code' => $item->internal_code,
                    'name' => $item->name,
                    'unit' => $item->unit,
                    'category' => $item->category?->name,
                    'brand' => $item->catalogMetadata?->brand?->name,
                    // Main warehouse (the first) and the units in each one.
                    'warehouse' => $stock ? ['publicId' => $stock->warehouse->public_id, 'name' => $stock->warehouse->name] : null,
                    'warehouses' => $rows->map(fn ($row): array => ['publicId' => $row->warehouse->public_id, 'name' => $row->warehouse->name, 'quantity' => (string) $row->quantity])->all(),
                    'quantity' => $quantity,
                    'reserved' => $held,
                    'available' => bcsub($quantity, $held, 6),
                    // Low: from the threshold of Sistema (5 by default) down; the Dashboard warns about these.
                    'low' => bccomp($quantity, (string) $threshold, 6) <= 0,
                    'salePrice' => (string) $item->sale_price,
                    'costPrice' => $item->cost_price === null ? null : (string) $item->cost_price,
                    'lastMovementAt' => isset($last[$item->id]) ? Carbon::parse($last[$item->id])->toIso8601String() : null,
                ];
            })->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'lowThreshold' => $threshold],
        ]);
    }

    /** «Últimos movimientos»: the whole kardex, newest first, with the document that caused each change. */
    public function movements(Request $request, CompanyContext $context): JsonResponse
    {
        $this->authorize('stock.view');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', Rule::in(['INITIAL', 'ADJUSTMENT', 'SALE', 'RETURN', 'PURCHASE', 'CONSUMPTION', 'ENTRY', 'EXIT', 'PROFORMA', 'PROFORMA_RELEASE', 'TRANSFER'])],
            // Filtros de Movimientos: Ingreso (suma) o Egreso (resta), y la bodega.
            'direction' => ['nullable', 'in:IN,OUT'],
            'warehousePublicId' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'itemPublicId' => ['nullable', 'uuid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = StockMovement::query()->where('company_id', $context->company()->id)
            ->with(['item', 'warehouse', 'document', 'purchase', 'adjustment', 'proforma', 'user'])
            ->latest('created_at')->latest('id');
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $pattern = '%'.mb_strtolower($search).'%';
            $query->whereHas('item', fn ($item) => $item->whereRaw('LOWER(name) LIKE ?', [$pattern])->orWhereRaw('LOWER(internal_code) LIKE ?', [$pattern]));
        }
        $query->when($filters['type'] ?? null, fn ($builder, $type) => $builder->where('type', $type))
            ->when(($filters['direction'] ?? null) === 'IN', fn ($builder) => $builder->where('quantity', '>', 0))
            ->when(($filters['direction'] ?? null) === 'OUT', fn ($builder) => $builder->where('quantity', '<', 0))
            ->when($filters['warehousePublicId'] ?? null, fn ($builder, $warehouse) => $builder->whereHas('warehouse', fn ($inner) => $inner->where('public_id', $warehouse)))
            ->when($filters['from'] ?? null, fn ($builder, $from) => $builder->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($builder, $to) => $builder->whereDate('created_at', '<=', $to))
            ->when($filters['itemPublicId'] ?? null, fn ($builder, $item) => $builder->whereHas('item', fn ($inner) => $inner->where('public_id', $item)));
        $page = $query->paginate($filters['per_page'] ?? 30);

        return response()->json([
            'data' => $page->getCollection()->map(fn (StockMovement $movement): array => [
                'publicId' => $movement->public_id,
                'type' => $movement->type,
                'reason' => $movement->reason,
                'quantity' => (string) $movement->quantity,
                'balance' => (string) $movement->balance,
                'item' => ['publicId' => $movement->item?->public_id, 'name' => $movement->item?->name, 'code' => $movement->item?->internal_code],
                'warehouse' => $movement->warehouse?->name,
                'document' => $this->document($movement),
                'note' => $movement->note,
                'user' => $movement->user?->name,
                'createdAt' => $movement->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * «Nuevo movimiento»: an entry or an exit of one product, with its reason, in the product's warehouse (the first
     * entry of a product without one says where it goes), or a Traslado of the product to another warehouse.
     */
    public function move(Request $request, CompanyContext $context, StockLedger $ledger, CompanySettings $settings, AuditRecorder $audit): JsonResponse
    {
        $this->authorize('stock.move');
        $data = $request->validate([
            'itemPublicId' => ['required', 'uuid'],
            'type' => ['required', Rule::in([StockMovement::ENTRY, StockMovement::EXIT, StockMovement::TRANSFER])],
            // ENTRY / EXIT: the warehouse (the main one if none); TRANSFER: from `warehousePublicId` to `toWarehousePublicId`.
            'warehousePublicId' => ['nullable', 'uuid'],
            'toWarehousePublicId' => ['nullable', 'required_if:type,TRANSFER', 'uuid'],
            'quantity' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'reason' => ['exclude_if:type,TRANSFER', 'required', Rule::in(StockMovement::REASONS)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $company = $context->company();
        $item = $company->items()->where('public_id', $data['itemPublicId'])->firstOrFail();
        $warehouse = $this->warehouse($company->id, $data['warehousePublicId'] ?? null);
        $destination = $this->warehouse($company->id, $data['toWarehousePublicId'] ?? null);
        $movement = DB::transaction(function () use ($ledger, $item, $data, $settings, $company, $request, $audit, $warehouse, $destination): StockMovement {
            if ($data['type'] === StockMovement::TRANSFER) {
                $movement = $ledger->transfer($item, $warehouse, $destination, $data['quantity'], $data['note'] ?? null, $request->user());
                $audit->record($request->user(), $company, 'stock.transferred', $item, null, ['from' => $data['warehousePublicId'] ?? null, 'to' => $destination->public_id, 'quantity' => $data['quantity']]);

                return $movement;
            }
            $movement = $ledger->move($item, $data['type'], $data['quantity'], $data['reason'], $data['note'] ?? null, $settings->allowsNegativeStock($company), $request->user(), $warehouse);
            $audit->record($request->user(), $company, 'stock.moved', $item, null, ['type' => $data['type'], 'quantity' => $data['quantity'], 'reason' => $data['reason']]);

            return $movement;
        });

        return response()->json(['data' => ['publicId' => $movement->public_id, 'balance' => (string) $movement->balance]], 201);
    }

    /** «Stock masivo» › Guardar: the counted units of several products at once. */
    public function adjust(Request $request, CompanyContext $context, StockLedger $ledger, AuditRecorder $audit): JsonResponse
    {
        $this->authorize('stock.adjust');
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.itemPublicId' => ['required', 'uuid'],
            'lines.*.warehousePublicId' => ['nullable', 'uuid'],
            'lines.*.counted' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
        ]);
        $company = $context->company();
        $items = $company->items()->whereIn('public_id', array_column($data['lines'], 'itemPublicId'))->get()->keyBy('public_id');
        $keys = array_map(fn (array $line): string => $line['itemPublicId'].':'.($line['warehousePublicId'] ?? ''), $data['lines']);
        if (count(array_unique($keys)) !== count($keys)) {
            throw ValidationException::withMessages(['lines' => 'Un producto se repite en la misma bodega.']);
        }
        if ($items->count() !== count(array_unique(array_column($data['lines'], 'itemPublicId')))) {
            throw ValidationException::withMessages(['lines' => 'Hay productos que no existen en la empresa.']);
        }
        $adjustment = DB::transaction(function () use ($ledger, $company, $data, $items, $request, $audit) {
            $adjustment = $ledger->adjust($company, array_map(fn (array $line) => ['item' => $items[$line['itemPublicId']], 'counted' => $line['counted'], 'warehouse' => $this->warehouse($company->id, $line['warehousePublicId'] ?? null)], $data['lines']), $data['note'] ?? null, $request->user());
            $audit->record($request->user(), $company, 'stock.adjusted', $adjustment, null, ['number' => $adjustment->displayNumber(), 'changed' => $adjustment->lines_count]);

            return $adjustment;
        });

        return response()->json(['data' => ['publicId' => $adjustment->public_id, 'number' => $adjustment->displayNumber(), 'changed' => $adjustment->lines_count]], 201);
    }

    private function warehouse(int $companyId, ?string $publicId): ?Warehouse
    {
        return $publicId === null ? null : Warehouse::query()->where('company_id', $companyId)->where('public_id', $publicId)->where('is_active', true)->firstOrFail();
    }

    /** @return array{kind: string, number: string, publicId: ?string}|null */
    private function document(StockMovement $movement): ?array
    {
        return match (true) {
            $movement->document !== null => ['kind' => $movement->document->document_type->value, 'number' => $movement->document->full_number, 'publicId' => $movement->document->public_id],
            $movement->purchase !== null => ['kind' => 'PURCHASE', 'number' => $movement->purchase->displayNumber(), 'publicId' => $movement->purchase->public_id],
            $movement->adjustment !== null => ['kind' => 'ADJUSTMENT', 'number' => $movement->adjustment->displayNumber(), 'publicId' => $movement->adjustment->public_id],
            $movement->proforma !== null => ['kind' => 'PROFORMA', 'number' => $movement->proforma->displayNumber(), 'publicId' => $movement->proforma->public_id],
            $movement->source_reference !== null => ['kind' => (string) $movement->source_module, 'number' => $movement->source_reference, 'publicId' => null],
            default => null,
        };
    }
}
