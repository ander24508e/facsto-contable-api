<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Catalog\Models\Item;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Inventory\Models\Warehouse;
use App\Http\Controllers\Controller;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Warehouses: one per establishment, created or renamed from the inventory form. */
final class WarehouseController extends Controller
{
    public function index(Request $request, CompanyContext $context): JsonResponse
    {
        $this->authorize('viewAny', Item::class);
        $query = Warehouse::query()->where('company_id', $context->company()->id)->with('establishment')
            ->withCount(['stocks as products_count' => fn ($builder) => $builder->where('quantity', '>', 0)])
            ->withSum('stocks as units_total', 'quantity')
            ->orderBy('name');
        // Without `status` only active ones (the inventory form); the Bodegas module sends ACTIVE, INACTIVE or ''.
        $status = $request->query('status', 'ACTIVE');
        if ($status === 'ACTIVE' || $status === 'INACTIVE') {
            $query->where('is_active', $status === 'ACTIVE');
        }
        if ($search = trim((string) $request->query('search'))) {
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']);
        }
        if ($establishment = $request->query('establishment')) {
            $query->whereHas('establishment', fn ($builder) => $builder->where('public_id', $establishment));
        }

        return response()->json(['data' => $query->get()->map($this->present(...))]);
    }

    public function show(Warehouse $warehouse, CompanyContext $context): JsonResponse
    {
        $this->authorize('viewAny', Item::class);
        if ($warehouse->company_id !== $context->company()->id) {
            throw new ApiException(ApiErrorCode::WAREHOUSE_NOT_FOUND, 'La bodega no existe en la empresa activa.', 404);
        }

        return response()->json(['data' => $this->present($warehouse->load('establishment'))]);
    }

    public function store(Request $request, CompanyContext $context, AuditRecorder $audit): JsonResponse
    {
        $this->authorize('create', Item::class);
        $data = $request->validate(['establishmentPublicId' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:80']]);
        $company = $context->company();
        $establishment = $company->establishments()->where('public_id', $data['establishmentPublicId'])->where('is_active', true)->first();
        if (! $establishment) {
            throw new ApiException(ApiErrorCode::ESTABLISHMENT_NOT_FOUND, 'El establecimiento no está activo en la empresa actual.', 422);
        }
        if (Warehouse::query()->where('establishment_id', $establishment->id)->exists()) {
            throw new ApiException(ApiErrorCode::WAREHOUSE_ALREADY_EXISTS, 'Este establecimiento ya tiene su bodega.', 409);
        }
        $warehouse = Warehouse::query()->create(['company_id' => $company->id, 'establishment_id' => $establishment->id, 'name' => trim($data['name'])]);
        $audit->record($request->user(), $company, 'warehouse.created', $warehouse, null, ['name' => $warehouse->name, 'establishment' => $establishment->code]);

        return response()->json(['data' => $this->present($warehouse->load('establishment'))], 201);
    }

    public function update(Request $request, Warehouse $warehouse, CompanyContext $context, AuditRecorder $audit): JsonResponse
    {
        $this->authorize('create', Item::class);
        $company = $context->company();
        if ($warehouse->company_id !== $company->id) {
            throw new ApiException(ApiErrorCode::WAREHOUSE_NOT_FOUND, 'La bodega no existe en la empresa activa.', 404);
        }
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $before = ['name' => $warehouse->name];
        $warehouse->forceFill(['name' => trim($data['name'])])->save();
        $audit->record($request->user(), $company, 'warehouse.updated', $warehouse, $before, ['name' => $warehouse->name]);

        return response()->json(['data' => $this->present($warehouse->load('establishment'))]);
    }

    public function activate(Request $request, Warehouse $warehouse, CompanyContext $context, AuditRecorder $audit): JsonResponse
    {
        return $this->setActive($request, $warehouse, $context, $audit, true);
    }

    /** A warehouse that still holds units cannot be deactivated: its products would be left nowhere. */
    public function deactivate(Request $request, Warehouse $warehouse, CompanyContext $context, AuditRecorder $audit): JsonResponse
    {
        if ($warehouse->stocks()->where('quantity', '>', 0)->exists()) {
            throw new ApiException(ApiErrorCode::WAREHOUSE_IN_USE, 'La bodega tiene productos con stock; muévelos antes de desactivarla.', 409);
        }

        return $this->setActive($request, $warehouse, $context, $audit, false);
    }

    private function setActive(Request $request, Warehouse $warehouse, CompanyContext $context, AuditRecorder $audit, bool $active): JsonResponse
    {
        $this->authorize('create', Item::class);
        $company = $context->company();
        if ($warehouse->company_id !== $company->id) {
            throw new ApiException(ApiErrorCode::WAREHOUSE_NOT_FOUND, 'La bodega no existe en la empresa activa.', 404);
        }
        if ($warehouse->is_active !== $active) {
            $warehouse->forceFill(['is_active' => $active])->save();
            $audit->record($request->user(), $company, $active ? 'warehouse.activated' : 'warehouse.deactivated', $warehouse, ['is_active' => ! $active], ['is_active' => $active]);
        }

        return response()->json(['data' => $this->present($warehouse->load('establishment'))]);
    }

    /** @return array<string, mixed> */
    private function present(Warehouse $warehouse): array
    {
        return [
            'publicId' => $warehouse->public_id,
            'name' => $warehouse->name,
            'establishmentPublicId' => $warehouse->establishment->public_id,
            'establishmentCode' => $warehouse->establishment->code,
            'establishmentName' => $warehouse->establishment->name,
            'isActive' => $warehouse->is_active,
            'productsCount' => (int) ($warehouse->products_count ?? $warehouse->stocks()->where('quantity', '>', 0)->count()),
            'unitsTotal' => bcadd((string) ($warehouse->units_total ?? $warehouse->stocks()->sum('quantity')), '0', 6),
        ];
    }
}
