<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Actions\CreateEmissionPointAction;
use App\Domains\Companies\Actions\UpdateEmissionPointAction;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\EmissionPoint;
use App\Domains\FiscalDocuments\Actions\ConfigureInitialSequentialAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Companies\ConfigureInitialSequentialRequest;
use App\Http\Requests\Companies\StoreEmissionPointRequest;
use App\Http\Requests\Companies\UpdateEmissionPointRequest;
use App\Http\Resources\Api\V1\EmissionPointResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class EmissionPointController extends Controller
{
    public function index(CompanyContext $context): AnonymousResourceCollection
    {
        $points = $context->company()->emissionPoints()->with('establishment')->orderBy('code')->get();

        return EmissionPointResource::collection($points);
    }

    public function store(StoreEmissionPointRequest $request, CreateEmissionPointAction $create, CompanyContext $context): EmissionPointResource
    {
        $this->authorize('update', $context->company());
        $data = $request->validated();
        $point = $create->execute($data['establishmentPublicId'], [
            'code' => $data['code'],
            'name' => $data['name'],
            'is_active' => $data['isActive'] ?? true,
            'is_default' => $data['isDefault'] ?? null,
        ]);

        return new EmissionPointResource($point->load('establishment'));
    }

    public function update(UpdateEmissionPointRequest $request, EmissionPoint $emissionPoint, UpdateEmissionPointAction $update, CompanyContext $context): EmissionPointResource
    {
        $this->authorize('update', $context->company());
        $data = $request->validated();
        $attributes = [];

        foreach (['code' => 'code', 'name' => 'name', 'isActive' => 'is_active', 'isDefault' => 'is_default'] as $input => $attribute) {
            if (array_key_exists($input, $data)) {
                $attributes[$attribute] = $data[$input];
            }
        }

        return new EmissionPointResource($update->execute($emissionPoint, $attributes)->load('establishment'));
    }

    /** First invoice sequential of the current RUC in this point; locked once it numbered there. */
    public function sequence(ConfigureInitialSequentialRequest $request, EmissionPoint $emissionPoint, ConfigureInitialSequentialAction $configure, CompanyContext $context): EmissionPointResource
    {
        $this->authorize('update', $context->company());

        return new EmissionPointResource($configure->execute($emissionPoint, (int) $request->validated('initialSequential'), $request->user())->load('establishment'));
    }
}
