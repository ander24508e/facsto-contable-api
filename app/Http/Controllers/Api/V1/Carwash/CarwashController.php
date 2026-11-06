<?php

namespace App\Http\Controllers\Api\V1\Carwash;

use App\Domains\Companies\Context\CompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Base of the Lavadora controllers: records are always looked up inside the active company. */
abstract class CarwashController extends Controller
{
    /**
     * A record by its public_id within the active company; another company's answers 404, like a missing one.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel
     */
    protected function owned(string $model, string $publicId): Model
    {
        /** @var Builder<TModel> $query */
        $query = $model::query()->where('company_id', $this->companyId())->where('public_id', $publicId);

        return $query->firstOrFail();
    }

    protected function companyId(): int
    {
        return app(CompanyContext::class)->company()->id;
    }
}
