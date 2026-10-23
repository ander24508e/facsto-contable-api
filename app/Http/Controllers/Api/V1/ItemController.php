<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Catalog\Actions\ActivateItemAction;
use App\Domains\Catalog\Actions\CreateItemAction;
use App\Domains\Catalog\Actions\DeactivateItemAction;
use App\Domains\Catalog\Actions\SyncItemTaxesAction;
use App\Domains\Catalog\Actions\UpdateItemAction;
use App\Domains\Catalog\Models\Item;
use App\Domains\Companies\Context\CompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreItemRequest;
use App\Http\Requests\Catalog\SyncItemTaxesRequest;
use App\Http\Requests\Catalog\UpdateItemRequest;
use App\Http\Resources\Api\V1\ItemResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ItemController extends Controller
{
    public function index(Request $request, CompanyContext $context): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Item::class);
        $query = $context->company()->items()->with(['taxes', 'category'])->orderBy('name');
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($search).'%'])->orWhereRaw('LOWER(internal_code) LIKE ?', ['%'.strtolower($search).'%']));
        }
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($request->query('status') === 'ACTIVE') {
            $query->where('is_active', true);
        }
        if ($request->query('status') === 'INACTIVE') {
            $query->where('is_active', false);
        }

        return ItemResource::collection($query->paginate(min(max((int) $request->query('per_page', 15), 1), 100)));
    }

    public function store(StoreItemRequest $request, CreateItemAction $create): ItemResource
    {
        $this->authorize('create', Item::class);

        return new ItemResource($create->execute($this->attributes($request->validated()), $request->user()));
    }

    public function show(Item $item): ItemResource
    {
        $this->authorize('view', $item);

        return new ItemResource($item->load(['taxes', 'category']));
    }

    public function update(UpdateItemRequest $request, Item $item, UpdateItemAction $update): ItemResource
    {
        $this->authorize('update', $item);

        return new ItemResource($update->execute($item, $this->attributes($request->validated()), $request->user()));
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

    public function syncTaxes(SyncItemTaxesRequest $request, Item $item, SyncItemTaxesAction $sync): ItemResource
    {
        $this->authorize('update', $item);

        return new ItemResource($sync->execute($item, $request->validated('taxPublicIds'), $request->user()));
    }

    private function attributes(array $data): array
    {
        foreach (['internalCode' => 'internal_code', 'auxiliaryCode' => 'auxiliary_code', 'salePrice' => 'sale_price', 'categoryPublicId' => 'category_public_id'] as $source => $target) {
            if (array_key_exists($source, $data)) {
                $data[$target] = $data[$source];
            }
            unset($data[$source]);
        }

        return $data;
    }
}
