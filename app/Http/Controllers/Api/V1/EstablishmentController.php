<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Actions\CreateEstablishmentAction;
use App\Domains\Companies\Actions\UpdateEstablishmentAction;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Establishment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\StoreEstablishmentRequest;
use App\Http\Requests\Companies\UpdateEstablishmentRequest;
use App\Http\Resources\Api\V1\EstablishmentResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class EstablishmentController extends Controller
{
    public function index(CompanyContext $context): AnonymousResourceCollection
    {
        return EstablishmentResource::collection($context->company()->establishments()->orderBy('code')->get());
    }

    public function store(StoreEstablishmentRequest $request, CreateEstablishmentAction $create, CompanyContext $context): EstablishmentResource
    {
        $this->authorize('update', $context->company());

        return new EstablishmentResource($create->execute([
            'code' => $request->validated('code'),
            'name' => $request->validated('name'),
            'address' => $request->validated('address'),
            'is_active' => $request->validated('isActive', true),
        ]));
    }

    public function update(UpdateEstablishmentRequest $request, Establishment $establishment, UpdateEstablishmentAction $update, CompanyContext $context): EstablishmentResource
    {
        $this->authorize('update', $context->company());
        $data = $request->validated();
        $attributes = [];

        foreach (['code' => 'code', 'name' => 'name', 'address' => 'address', 'isActive' => 'is_active'] as $input => $attribute) {
            if (array_key_exists($input, $data)) {
                $attributes[$attribute] = $data[$input];
            }
        }

        return new EstablishmentResource($update->execute($establishment, $attributes));
    }
}
