<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Catalog\Models\Item;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Purchases\Actions\CreateSupplierAction;
use App\Domains\Companies\Fiscal\Services\EcuadorRucValidator;
use App\Domains\Purchases\Models\Supplier;
use App\Http\Controllers\Controller;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Proveedores: RUC (or cédula), legal name and contact. Created from their module or from the purchase
 * form of Inversiones; a deactivated supplier keeps its purchases but cannot be chosen for new ones.
 */
final class SupplierController extends Controller
{
    public function index(Request $request, CompanyContext $context): JsonResponse
    {
        $this->authorize('viewAny', Item::class);
        $query = Supplier::query()->where('company_id', $context->company()->id)->withCount('purchases')->orderBy('legal_name');
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($builder) => $builder->where('identification', 'like', $search.'%')
                ->orWhereRaw('LOWER(legal_name) LIKE ?', ['%'.mb_strtolower($search).'%']));
        }
        match ($request->query('status')) {
            'ACTIVE' => $query->where('is_active', true),
            'INACTIVE' => $query->where('is_active', false),
            default => null,
        };
        $page = $query->paginate(min(max((int) $request->query('per_page', 15), 1), 100));

        return response()->json([
            'data' => collect($page->items())->map($this->present(...)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(CompanyContext $context, string $supplier): JsonResponse
    {
        $this->authorize('viewAny', Item::class);

        return response()->json(['data' => $this->present($this->find($context, $supplier)->loadCount('purchases'))]);
    }

    public function store(Request $request, CompanyContext $context, EcuadorRucValidator $validator, CreateSupplierAction $create): JsonResponse
    {
        $this->authorize('create', Item::class);
        $company = $context->company();
        $data = $this->validated($request, $validator, true);
        $supplier = $create->execute($company, $data, $request->user());

        return response()->json(['data' => $this->present($supplier)], 201);
    }

    /** The identification never changes: it is what the supplier's purchases point to. */
    public function update(Request $request, CompanyContext $context, EcuadorRucValidator $validator, AuditRecorder $audit, string $supplier): JsonResponse
    {
        $this->authorize('create', Item::class);
        $model = $this->find($context, $supplier);
        $before = $model->only(['legal_name', 'email', 'phone', 'address']);
        $model->forceFill($this->validated($request, $validator, false))->save();
        $audit->record($request->user(), $context->company(), 'supplier.updated', $model, $before, $model->only(['legal_name', 'email', 'phone', 'address']));

        return response()->json(['data' => $this->present($model->loadCount('purchases'))]);
    }

    public function activate(Request $request, CompanyContext $context, AuditRecorder $audit, string $supplier): JsonResponse
    {
        return $this->toggle($request, $context, $audit, $supplier, true);
    }

    public function deactivate(Request $request, CompanyContext $context, AuditRecorder $audit, string $supplier): JsonResponse
    {
        return $this->toggle($request, $context, $audit, $supplier, false);
    }

    private function toggle(Request $request, CompanyContext $context, AuditRecorder $audit, string $supplier, bool $active): JsonResponse
    {
        $this->authorize('create', Item::class);
        $model = $this->find($context, $supplier);
        $model->forceFill(['is_active' => $active])->save();
        $audit->record($request->user(), $context->company(), $active ? 'supplier.activated' : 'supplier.deactivated', $model, ['is_active' => ! $active], ['is_active' => $active]);

        return response()->json(['data' => $this->present($model->loadCount('purchases'))]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, EcuadorRucValidator $validator, bool $creating): array
    {
        $data = $request->validate([
            'identification' => [$creating ? 'required' : 'prohibited', 'string', 'regex:/^(\d{10}|\d{13})$/'],
            'legalName' => ['required', 'string', 'max:300'],
            'email' => ['nullable', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:300'],
        ]);
        $values = [
            'legal_name' => mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $data['legalName']))),
            'email' => filled($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : null,
            'phone' => filled($data['phone'] ?? null) ? trim($data['phone']) : null,
            'address' => filled($data['address'] ?? null) ? trim($data['address']) : null,
        ];
        if ($creating) {
            $identification = $data['identification'];
            $valid = strlen($identification) === 13 ? $validator->isStructurallyValid($identification) : $validator->isValidCedula($identification);
            if (! $valid) {
                throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'El RUC o la cédula del proveedor no es válido.', 422);
            }
            $values['identification'] = $identification;
        }

        return $values;
    }

    private function find(CompanyContext $context, string $publicId): Supplier
    {
        return Supplier::query()->where('company_id', $context->company()->id)->where('public_id', $publicId)->first()
            ?? throw new ApiException(ApiErrorCode::SUPPLIER_NOT_FOUND, 'El proveedor no existe en la empresa actual.', 404);
    }

    private function present(Supplier $supplier): array
    {
        return [
            'publicId' => $supplier->public_id, 'identification' => $supplier->identification, 'legalName' => $supplier->legal_name,
            'email' => $supplier->email, 'phone' => $supplier->phone, 'address' => $supplier->address,
            'isActive' => (bool) $supplier->is_active, 'purchasesCount' => $supplier->purchases_count,
            'createdAt' => $supplier->created_at?->toIso8601String(),
        ];
    }
}
