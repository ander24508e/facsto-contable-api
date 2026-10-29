<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Category;
use App\Domains\Companies\Context\CompanyContext;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/** Removes a category with no items; its default taxes go with it. One with items can only be deactivated. */
final class DeleteCategoryAction
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditRecorder $audit) {}

    public function execute(Category $category, User $actor): void
    {
        $company = $this->context->company();
        if ($category->company_id !== $company->id) {
            throw new ApiException(ApiErrorCode::CATEGORY_NOT_FOUND, 'La categoría no existe en la empresa activa.', 404);
        }
        if ($category->items()->exists()) {
            throw new ApiException(ApiErrorCode::CATEGORY_IN_USE, 'La categoría tiene productos; solo puedes desactivarla.', 409);
        }

        DB::transaction(function () use ($category, $actor, $company): void {
            $before = $category->only(['name', 'description', 'is_active']);
            $category->taxes()->detach();
            $category->delete();
            $this->audit->record($actor, $company, 'category.deleted', $category, $before, []);
        });
    }
}
