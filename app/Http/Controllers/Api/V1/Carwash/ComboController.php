<?php

namespace App\Http\Controllers\Api\V1\Carwash;

use App\Domains\Carwash\Models\Combo;
use App\Domains\Carwash\Models\Service;
use App\Domains\Companies\Context\CompanyContext;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Configuración › Tarifas «Combos»: two or more services with a discount when an order has them all. */
final class ComboController extends CarwashController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Combo::query()->with('services.item')->where('company_id', $this->companyId())->orderBy('name')->get()->map(fn (Combo $combo) => $this->present($combo))->values()]);
    }

    public function store(Request $request, CompanyContext $context, AuditRecorder $audit): JsonResponse
    {
        $combo = $this->save(new Combo(['company_id' => $this->companyId()]), $request, $context, $audit);

        return response()->json(['data' => $this->present($combo)], 201);
    }

    public function update(Request $request, string $combo, CompanyContext $context, AuditRecorder $audit): JsonResponse
    {
        return response()->json(['data' => $this->present($this->save($this->owned(Combo::class, $combo), $request, $context, $audit))]);
    }

    private function save(Combo $combo, Request $request, CompanyContext $context, AuditRecorder $audit): Combo
    {
        $creating = ! $combo->exists;
        $data = $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:80'],
            'discountPercent' => [$creating ? 'required' : 'sometimes', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'servicePublicIds' => [$creating ? 'required' : 'sometimes', 'array', 'min:2', 'max:10'],
            'servicePublicIds.*' => ['uuid', 'distinct'],
            'isActive' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['discountPercent']) && (bccomp($data['discountPercent'], '0', 2) <= 0 || bccomp($data['discountPercent'], '100', 2) > 0)) {
            throw ValidationException::withMessages(['discountPercent' => 'El descuento va de 0.01 a 100 %.']);
        }
        if (isset($data['name'])) {
            $taken = Combo::query()->where('company_id', $this->companyId())->whereRaw('lower(name) = ?', [mb_strtolower(trim($data['name']))])
                ->when($combo->exists, fn ($query) => $query->whereKeyNot($combo->id))->exists();
            if ($taken) {
                throw new ApiException(ApiErrorCode::CARWASH_DUPLICATE_NAME, 'Ya existe un combo con ese nombre.', 409, ['errors' => ['name' => ['Ya existe un combo con ese nombre.']]]);
            }
        }
        $services = isset($data['servicePublicIds'])
            ? Service::query()->where('company_id', $this->companyId())->whereIn('public_id', $data['servicePublicIds'])->get()
            : null;
        if ($services !== null && $services->count() !== count($data['servicePublicIds'])) {
            throw ValidationException::withMessages(['servicePublicIds' => 'Uno de los servicios no existe en Tarifas.']);
        }
        $before = $combo->exists ? $this->present($combo->load('services.item')) : null;

        DB::transaction(function () use ($combo, $data, $services): void {
            $combo->fill(array_filter([
                'name' => isset($data['name']) ? trim($data['name']) : null,
                'discount_percent' => $data['discountPercent'] ?? null,
                'is_active' => $data['isActive'] ?? null,
            ], fn ($value) => $value !== null))->save();
            if ($services !== null) {
                $combo->services()->sync($services->mapWithKeys(fn (Service $service) => [$service->id => ['company_id' => $combo->company_id]])->all());
            }
        });
        $combo->load('services.item');
        $audit->record($request->user(), $context->company(), $before ? 'carwash.combo.updated' : 'carwash.combo.created', $combo, $before, $this->present($combo));

        return $combo;
    }

    /** @return array<string, mixed> */
    private function present(Combo $combo): array
    {
        return [
            'publicId' => $combo->public_id,
            'name' => $combo->name,
            'discountPercent' => (string) $combo->discount_percent,
            'isActive' => (bool) $combo->is_active,
            'services' => $combo->services->map(fn (Service $service) => ['publicId' => $service->public_id, 'name' => $service->item?->name])->values()->all(),
        ];
    }
}
