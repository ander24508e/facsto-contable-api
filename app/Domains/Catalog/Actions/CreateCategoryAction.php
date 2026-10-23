<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Category;
use App\Domains\Companies\Context\CompanyContext;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

final class CreateCategoryAction
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditRecorder $audit) {}

    public function execute(array $attributes, User $actor): Category
    {
        $company = $this->context->company();
        $name = trim($attributes['name']);
        if ($company->categories()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            throw new ApiException(ApiErrorCode::CATEGORY_DUPLICATE, 'Ya existe una categoría con ese nombre en esta empresa.', 409);
        }

        return DB::transaction(function () use ($company, $name, $attributes, $actor): Category {
            $category = $company->categories()->create(['name' => $name, 'description' => $attributes['description'] ?? null, 'is_active' => true]);
            $this->audit->record($actor, $company, 'category.created', $category, null, $category->only(['name', 'description', 'is_active']));

            return $category;
        });
    }
}
