<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Catalog\Actions\ActivateCategoryAction;
use App\Domains\Catalog\Actions\CreateCategoryAction;
use App\Domains\Catalog\Actions\DeactivateCategoryAction;
use App\Domains\Catalog\Actions\DeleteCategoryAction;
use App\Domains\Catalog\Actions\UpdateCategoryAction;
use App\Domains\Catalog\Models\Category;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Taxes\Services\ItemTaxService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreCategoryRequest;
use App\Http\Requests\Catalog\UpdateCategoryRequest;
use App\Http\Resources\Api\V1\CategoryResource;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class CategoryController extends Controller
{
    public function index(Request $request, CompanyContext $context): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Category::class);
        $query = $context->company()->categories()->with('taxes.definition')->withCount('items')->orderBy('name');
        if ($search = trim((string) $request->query('search'))) {
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']);
        }
        if ($request->query('status') === 'ACTIVE') {
            $query->where('is_active', true);
        } elseif ($request->query('status') === 'INACTIVE') {
            $query->where('is_active', false);
        }

        return CategoryResource::collection($query->paginate(min(max((int) $request->query('per_page', 15), 1), 100)));
    }

    public function store(StoreCategoryRequest $request, CreateCategoryAction $create): CategoryResource
    {
        $this->authorize('create', Category::class);

        return new CategoryResource($create->execute($request->validated(), $request->user()));
    }

    public function show(Category $category): CategoryResource
    {
        $this->authorize('view', $category);

        return new CategoryResource($category->load('taxes.definition'));
    }

    public function update(UpdateCategoryRequest $request, Category $category, UpdateCategoryAction $update): CategoryResource
    {
        $this->authorize('update', $category);

        return new CategoryResource($update->execute($category, $request->validated(), $request->user()));
    }

    /** PUT /categories/{category}/taxes — default rates inherited by new items of the category. */
    public function syncTaxes(Request $request, Category $category, ItemTaxService $taxes, AuditRecorder $audit): CategoryResource
    {
        $this->authorize('update', $category);
        $ids = $request->validate(['taxPublicIds' => ['present', 'array'], 'taxPublicIds.*' => ['uuid', 'distinct']])['taxPublicIds'];
        $before = $category->taxes()->pluck('company_taxes.public_id')->all();
        $taxes->syncCategory($category, $ids);
        $audit->record($request->user(), $category->company, 'category.taxes_updated', $category, ['taxPublicIds' => $before], ['taxPublicIds' => $ids]);

        return new CategoryResource($category->load('taxes.definition'));
    }

    public function activate(Request $request, Category $category, ActivateCategoryAction $activate): CategoryResource
    {
        $this->authorize('update', $category);

        return new CategoryResource($activate->execute($category, $request->user()));
    }

    public function deactivate(Request $request, Category $category, DeactivateCategoryAction $deactivate): CategoryResource
    {
        $this->authorize('update', $category);

        return new CategoryResource($deactivate->execute($category, $request->user()));
    }

    /** DELETE /categories/{category} — only for a category without items. */
    public function destroy(Request $request, Category $category, DeleteCategoryAction $delete): Response
    {
        $this->authorize('delete', $category);
        $delete->execute($category, $request->user());

        return response()->noContent();
    }
}
