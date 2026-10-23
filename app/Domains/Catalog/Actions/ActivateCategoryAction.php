<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Catalog\Models\Category;
use App\Domains\Companies\Context\CompanyContext;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;

final class ActivateCategoryAction
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditRecorder $audit) {}

    public function execute(Category $category, User $actor): Category
    {
        $company = $this->context->company();
        if ($category->company_id !== $company->id) {
            throw new ApiException(ApiErrorCode::CATEGORY_NOT_FOUND, 'La categoría no existe en la empresa activa.', 404);
        }
        if (! $category->is_active) {
            $category->forceFill(['is_active' => true])->save();
            $this->audit->record($actor, $company, 'category.activated', $category, ['is_active' => false], ['is_active' => true]);
        }

        return $category->refresh();
    }
}
