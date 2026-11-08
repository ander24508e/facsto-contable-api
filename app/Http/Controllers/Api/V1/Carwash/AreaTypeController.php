<?php

namespace App\Http\Controllers\Api\V1\Carwash;

use App\Domains\Carwash\Actions\SaveNamedEntryAction;
use App\Domains\Carwash\Models\AreaType;
use App\Domains\Carwash\Services\AreaTypeCatalog;
use App\Http\Resources\Api\V1\Carwash\AreaTypeResource;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Tipos de área: Lavado, Secado y Lubricación fijos y los que cree la empresa (bar, pulido, tapicería…). Los fijos no
 * se renombran ni se desactivan; los demás no se eliminan, se desactivan (sus áreas los conservan).
 */
final class AreaTypeController extends CarwashController
{
    public function index(Request $request, AreaTypeCatalog $catalog): AnonymousResourceCollection
    {
        $catalog->ensureFixed($this->companyId());

        return AreaTypeResource::collection(AreaType::query()
            ->where('company_id', $this->companyId())
            ->when(! $request->boolean('includeInactive'), fn ($query) => $query->where('is_active', true))
            ->withCount('areas')
            ->ordered()
            ->get());
    }

    public function store(Request $request, SaveNamedEntryAction $save, AreaTypeCatalog $catalog): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);
        $catalog->ensureFixed($this->companyId());

        return (new AreaTypeResource($save->execute(new AreaType, ['name' => $data['name']], $request->user())->loadCount('areas')))
            ->response()->setStatusCode(201);
    }

    public function update(Request $request, string $areaType, SaveNamedEntryAction $save): AreaTypeResource
    {
        $type = $this->owned(AreaType::class, $areaType);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'isActive' => ['sometimes', 'boolean'],
        ]);
        if ($type->isFixed()) {
            throw new ApiException(ApiErrorCode::CARWASH_AREA_TYPE_FIXED, "«{$type->name}» viene fijo: no se cambia ni se desactiva.", 422);
        }
        $attributes = array_filter(['name' => $data['name'] ?? null, 'is_active' => $data['isActive'] ?? null], fn ($value) => $value !== null);

        return new AreaTypeResource($save->execute($type, $attributes, $request->user())->loadCount('areas'));
    }
}
