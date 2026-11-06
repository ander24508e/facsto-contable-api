<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Catalog\Actions\ActivateItemAction;
use App\Domains\Catalog\Actions\CreateItemAction;
use App\Domains\Catalog\Actions\DeactivateItemAction;
use App\Domains\Catalog\Actions\DeleteItemAction;
use App\Domains\Catalog\Actions\DeleteItemImageAction;
use App\Domains\Catalog\Actions\SyncItemTaxesAction;
use App\Domains\Catalog\Actions\UpdateItemAction;
use App\Domains\Catalog\Actions\UpdateItemImageAction;
use App\Domains\Catalog\Models\Item;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Services\CompanySettings;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Catalog\Services\BarcodeGenerator;
use App\Domains\Inventory\Services\ItemCodeSuggester;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreItemRequest;
use App\Http\Requests\Catalog\SyncItemTaxesRequest;
use App\Http\Requests\Catalog\UpdateItemRequest;
use App\Http\Resources\Api\V1\ItemResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ItemController extends Controller
{
    /** GET /items/next-code?name=&category= — the suggested main code for a new item (BEB-AGU-001). */
    public function nextCode(Request $request, CompanyContext $context, ItemCodeSuggester $suggester): JsonResponse
    {
        $this->authorize('create', Item::class);
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], 'category' => ['nullable', 'uuid']]);
        $category = isset($data['category']) ? $context->company()->categories()->where('public_id', $data['category'])->value('name') : null;

        return response()->json(['data' => ['code' => $suggester->suggest($context->company(), $data['name'], $category)]]);
    }

    /** GET /items/next-barcode — the next internal EAN-13 (prefix 20) for a product without a factory code. */
    public function nextBarcode(CompanyContext $context, BarcodeGenerator $barcodes): JsonResponse
    {
        $this->authorize('create', Item::class);

        return response()->json(['data' => ['barcode' => $barcodes->next($context->company())]]);
    }

    public function index(Request $request, CompanyContext $context): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Item::class);
        $query = $context->company()->items()->with(['taxes.definition', 'category', 'catalogMetadata.brand', 'stocks.warehouse.establishment', 'priceTiers'])->orderBy('name');
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($search).'%'])
                ->orWhereRaw('LOWER(internal_code) LIKE ?', ['%'.strtolower($search).'%'])
                ->orWhereHas('catalogMetadata', fn ($metadata) => $metadata->whereRaw('LOWER(barcode) LIKE ?', ['%'.strtolower($search).'%'])));
        }
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        // Dashboard «stock bajo»: products with 5 units or fewer.
        if ($request->query('stock') === 'low') {
            $threshold = app(CompanySettings::class)->lowStockThreshold($context->company());
            $query->where('type', 'PRODUCT')->whereHas('stocks', fn ($builder) => $builder->where('quantity', '<=', $threshold));
        }
        if ($warehouse = $request->query('warehouse')) {
            $query->whereHas('stocks.warehouse', fn ($builder) => $builder->where('public_id', $warehouse));
        }
        if ($category = $request->query('category')) {
            $query->whereHas('category', fn ($builder) => $builder->where('public_id', $category));
        }
        if ($request->query('status') === 'ACTIVE') {
            $query->where('is_active', true);
        }
        if ($request->query('status') === 'INACTIVE') {
            $query->where('is_active', false);
        }

        return ItemResource::collection($query->paginate(min(max((int) $request->query('per_page', 15), 1), 100)));
    }

    /** GET /items/frequent — the active items sold most in the last 90 days first (quick sale buttons). */
    public function frequent(Request $request, CompanyContext $context): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Item::class);
        $limit = min(max((int) $request->query('limit', 12), 1), 48);
        $company = $context->company();
        $sold = DB::table('sale_details')
            ->join('sales', 'sales.id', '=', 'sale_details.sale_id')
            ->where('sale_details.company_id', $company->id)
            ->where('sales.created_at', '>=', now()->subDays(90))
            ->groupBy('sale_details.item_id')
            ->selectRaw('sale_details.item_id, COUNT(*) as uses');

        $items = $company->items()->where('is_active', true)->with(['taxes.definition', 'category', 'catalogMetadata.brand'])
            ->leftJoinSub($sold, 'sold', 'sold.item_id', '=', 'items.id')
            ->orderByRaw('COALESCE(sold.uses, 0) DESC')
            ->orderBy('items.name')
            ->limit($limit)
            ->get(['items.*']);

        return ItemResource::collection($items);
    }

    public function store(StoreItemRequest $request, CreateItemAction $create): ItemResource
    {
        $this->authorize('create', Item::class);

        return new ItemResource($create->execute($this->attributes($request->validated()), $request->user()));
    }

    public function show(Item $item): ItemResource
    {
        $this->authorize('view', $item);

        return new ItemResource($item->load(['taxes.definition', 'category', 'catalogMetadata.brand', 'stocks.warehouse.establishment', 'priceTiers']));
    }

    /** The kardex of a product: every stock change, newest first, with its warehouse, document and user. */
    public function stockMovements(Item $item): JsonResponse
    {
        $this->authorize('view', $item);
        $movements = StockMovement::query()->where('item_id', $item->id)->with(['warehouse', 'document', 'user'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get();

        return response()->json(['data' => $movements->map(fn (StockMovement $movement): array => [
            'publicId' => $movement->public_id,
            'type' => $movement->type,
            'quantity' => (string) $movement->quantity,
            'balance' => (string) $movement->balance,
            'warehouseName' => $movement->warehouse->name,
            'documentPublicId' => $movement->document?->public_id,
            'documentNumber' => $movement->document?->full_number,
            'note' => $movement->note,
            'user' => $movement->user?->name,
            'createdAt' => $movement->created_at->toIso8601String(),
        ])]);
    }

    public function update(UpdateItemRequest $request, Item $item, UpdateItemAction $update): ItemResource
    {
        $this->authorize('update', $item);

        return new ItemResource($update->execute($item, $this->attributes($request->validated()), $request->user()));
    }

    /** DELETE /items/{item} — only for an item that is in no sale. */
    public function destroy(Request $request, Item $item, DeleteItemAction $delete): Response
    {
        $this->authorize('delete', $item);
        $delete->execute($item, $request->user());

        return response()->noContent();
    }

    public function activate(Request $request, Item $item, ActivateItemAction $activate): ItemResource
    {
        $this->authorize('update', $item);

        return new ItemResource($activate->execute($item, $request->user()));
    }

    public function deactivate(Request $request, Item $item, DeactivateItemAction $deactivate): ItemResource
    {
        $this->authorize('update', $item);

        return new ItemResource($deactivate->execute($item, $request->user()));
    }

    /** GET /items/{item}/image — the private photo, only for members of the company. */
    public function image(Item $item): StreamedResponse
    {
        $this->authorize('view', $item);
        abort_unless($item->image_path && Storage::disk('private')->exists($item->image_path), 404);

        return Storage::disk('private')->response($item->image_path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function updateImage(Request $request, Item $item, UpdateItemImageAction $update): ItemResource
    {
        $this->authorize('update', $item);
        $image = $request->validate(
            ['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'dimensions:max_width=4096,max_height=4096', 'max:3072']],
            ['image.max' => 'La foto no puede superar 3 MB.', 'image.mimes' => 'Usa una foto PNG, JPG o WebP.'],
        )['image'];

        return new ItemResource($update->execute($item, $image, $request->user()));
    }

    public function deleteImage(Request $request, Item $item, DeleteItemImageAction $delete): ItemResource
    {
        $this->authorize('update', $item);

        return new ItemResource($delete->execute($item, $request->user()));
    }

    public function syncTaxes(SyncItemTaxesRequest $request, Item $item, SyncItemTaxesAction $sync): ItemResource
    {
        $this->authorize('update', $item);

        return new ItemResource($sync->execute($item, $request->validated('taxPublicIds'), $request->user()));
    }

    private function attributes(array $data): array
    {
        // «barcode» keeps its name, so it is not in the map (unsetting it would drop the value).
        foreach (['internalCode' => 'internal_code', 'auxiliaryCode' => 'auxiliary_code', 'salePrice' => 'sale_price', 'priceIncludesTax' => 'price_includes_tax', 'categoryPublicId' => 'category_public_id', 'brandPublicId' => 'brand_public_id', 'costPrice' => 'cost_price', 'additionalDetail' => 'additional_detail', 'contentQuantity' => 'content_quantity', 'contentUnit' => 'content_unit', 'priceTiers' => 'price_tiers'] as $source => $target) {
            if (array_key_exists($source, $data)) {
                $data[$target] = $data[$source];
            }
            unset($data[$source]);
        }

        return $data;
    }
}
