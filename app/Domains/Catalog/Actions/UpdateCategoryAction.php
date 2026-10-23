<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Category;
use App\Domains\Companies\Context\CompanyContext;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class UpdateCategoryAction
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditRecorder $audit) {}

    public function execute(Category $category, array $attributes, User $actor): Category
    {
        $company = $this->context->company();
        if ($category->company_id !== $company->id) {
            throw new ApiException(ApiErrorCode::CATEGORY_NOT_FOUND, 'La categoría no existe en la empresa activa.', 404);
        }
        $name = trim($attributes['name'] ?? $category->name);
        if ($company->categories()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->where('id', '<>', $category->id)->exists()) {
            throw new ApiException(ApiErrorCode::CATEGORY_DUPLICATE, 'Ya existe una categoría con ese nombre en esta empresa.', 409);
        }

        return DB::transaction(function () use ($category, $attributes, $actor, $company, $name): Category {
            $before = $category->only(['name', 'description', 'is_active']);
            $category->fill([...$attributes, 'name' => $name])->save();
            $this->audit->record($actor, $company, 'category.updated', $category, $before, $category->only(['name', 'description', 'is_active']));

            return $category->refresh();
        });
    }
}
