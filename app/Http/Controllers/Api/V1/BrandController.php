<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Catalog\Actions\ActivateBrandAction;
use App\Domains\Catalog\Actions\CreateBrandAction;
use App\Domains\Catalog\Actions\DeactivateBrandAction;
use App\Domains\Catalog\Actions\DeleteBrandAction;
use App\Domains\Catalog\Actions\UpdateBrandAction;
use App\Domains\Catalog\Models\Brand;
use App\Domains\Companies\Context\CompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreBrandRequest;
use App\Http\Requests\Catalog\UpdateBrandRequest;
use App\Http\Resources\Api\V1\BrandResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class BrandController extends Controller
{
    public function index(Request $request, CompanyContext $context): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Brand::class);
        $query = $context->company()->brands()->withCount('itemMetadata')->orderBy('name');
        if ($search = trim((string) $request->query('search'))) {
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']);
        }
        if ($request->query('status') === 'ACTIVE') {
            $query->where('is_active', true);
        } elseif ($request->query('status') === 'INACTIVE') {
            $query->where('is_active', false);
        }

        return BrandResource::collection($query->paginate(min(max((int) $request->query('per_page', 15), 1), 100)));
    }

    public function store(StoreBrandRequest $request, CreateBrandAction $create): BrandResource
    {
        $this->authorize('create', Brand::class);

        return new BrandResource($create->execute($request->validated(), $request->user()));
    }

    public function show(Brand $brand): BrandResource
    {
        $this->authorize('view', $brand);

        return new BrandResource($brand->loadCount('itemMetadata'));
    }

    public function update(UpdateBrandRequest $request, Brand $brand, UpdateBrandAction $update): BrandResource
    {
        $this->authorize('update', $brand);

        return new BrandResource($update->execute($brand, $request->validated(), $request->user()));
    }

    public function activate(Request $request, Brand $brand, ActivateBrandAction $activate): BrandResource
    {
        $this->authorize('update', $brand);

        return new BrandResource($activate->execute($brand, $request->user()));
    }

    public function deactivate(Request $request, Brand $brand, DeactivateBrandAction $deactivate): BrandResource
    {
        $this->authorize('update', $brand);

        return new BrandResource($deactivate->execute($brand, $request->user()));
    }

    public function destroy(Request $request, Brand $brand, DeleteBrandAction $delete): Response
    {
        $this->authorize('delete', $brand);
        $delete->execute($brand, $request->user());

        return response()->noContent();
    }
}
