<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Catalog\Actions\ActivateCategoryAction;
use App\Domains\Catalog\Actions\CreateCategoryAction;
use App\Domains\Catalog\Actions\DeactivateCategoryAction;
use App\Domains\Catalog\Actions\UpdateCategoryAction;
use App\Domains\Catalog\Models\Category;
use App\Domains\Companies\Context\CompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreCategoryRequest;
use App\Http\Requests\Catalog\UpdateCategoryRequest;
use App\Http\Resources\Api\V1\CategoryResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CategoryController extends Controller
{
    public function index(Request $request, CompanyContext $context): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Category::class);
        $query = $context->company()->categories()->orderBy('name');
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

        return new CategoryResource($category);
    }

    public function update(UpdateCategoryRequest $request, Category $category, UpdateCategoryAction $update): CategoryResource
    {
        $this->authorize('update', $category);

        return new CategoryResource($update->execute($category, $request->validated(), $request->user()));
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
}
