<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Actions\EnsureDefaultEmissionStructureAction;
use App\Domains\Companies\Context\CompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\EmissionPointResource;
use App\Http\Resources\Api\V1\EstablishmentResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

final class EnsureDefaultEmissionStructureController extends Controller
{
    public function __invoke(Request $request, CompanyContext $context, EnsureDefaultEmissionStructureAction $ensure): JsonResponse
    {
        $company = $context->company();
        $this->authorize('update', $company);
        $structure = $ensure->execute($company);

        return response()->json(['data' => [
            'establishment' => $structure['establishment']
                ? (new EstablishmentResource($structure['establishment']))->resolve($request)
                : null,
            'emissionPoint' => $structure['emissionPoint']
                ? (new EmissionPointResource($structure['emissionPoint']))->resolve($request)
                : null,
        ]]);
    }
}
