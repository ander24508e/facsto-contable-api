<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Catalog\Models\Item;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Inventory\Models\Unit;
use App\Http\Controllers\Controller;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Units of measure of the company; the first visit creates the usual ones (UND, KG, LT…). */
final class UnitController extends Controller
{
    public function index(CompanyContext $context): JsonResponse
    {
        $this->authorize('viewAny', Item::class);
        $company = $context->company();
        if (! Unit::query()->where('company_id', $company->id)->exists()) {
            foreach (Unit::DEFAULTS as $name) {
                Unit::query()->create(['company_id' => $company->id, 'name' => $name]);
            }
        }

        return response()->json(['data' => Unit::query()->where('company_id', $company->id)->where('is_active', true)->orderBy('name')->get()->map($this->present(...))]);
    }

    public function store(Request $request, CompanyContext $context, AuditRecorder $audit): JsonResponse
    {
        $this->authorize('create', Item::class);
        $name = $this->name($request);
        $company = $context->company();
        $this->assertUnique($company->id, $name);
        $unit = Unit::query()->create(['company_id' => $company->id, 'name' => $name]);
        $audit->record($request->user(), $company, 'unit.created', $unit, null, ['name' => $name]);

        return response()->json(['data' => $this->present($unit)], 201);
    }

    public function update(Request $request, Unit $unit, CompanyContext $context, AuditRecorder $audit): JsonResponse
    {
        $this->authorize('create', Item::class);
        $company = $context->company();
        abort_unless($unit->company_id === $company->id, 404);
        $name = $this->name($request);
        $this->assertUnique($company->id, $name, $unit->id);
        $before = ['name' => $unit->name];
        $unit->forceFill(['name' => $name])->save();
        $audit->record($request->user(), $company, 'unit.updated', $unit, $before, ['name' => $name]);

        return response()->json(['data' => $this->present($unit)]);
    }

    private function name(Request $request): string
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:32', 'regex:/^[\pL\pN .\-\/]+$/u']], ['name.regex' => 'La unidad solo admite letras, números, espacios, punto, guion y barra.']);

        return Str::upper(trim(preg_replace('/\s+/', ' ', $data['name'])));
    }

    private function assertUnique(int $companyId, string $name, ?int $ignore = null): void
    {
        if (Unit::query()->where('company_id', $companyId)->where('name', $name)->when($ignore, fn ($query) => $query->where('id', '<>', $ignore))->exists()) {
            throw new ApiException(ApiErrorCode::UNIT_DUPLICATE, 'Esa unidad ya existe.', 409);
        }
    }

    /** @return array<string, mixed> */
    private function present(Unit $unit): array
    {
        return ['publicId' => $unit->public_id, 'name' => $unit->name];
    }
}
