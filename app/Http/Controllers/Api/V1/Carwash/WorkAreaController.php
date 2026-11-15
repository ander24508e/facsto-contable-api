<?php

namespace App\Http\Controllers\Api\V1\Carwash;

use App\Domains\Carwash\Actions\SaveNamedEntryAction;
use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\AreaType;
use App\Domains\Carwash\Models\WorkArea;
use App\Domains\Carwash\Services\AreaTypeCatalog;
use App\Domains\Companies\Context\CompanyContext;
use App\Http\Resources\Api\V1\Carwash\WorkAreaResource;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Áreas de trabajo: one or two area types (Lavado, Secado, Lubricación or the company's), vehicles at once and hours.
 * They are not deleted, they are turned off; an inactive area takes no new reservations.
 */
final class WorkAreaController extends CarwashController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $today = CarbonImmutable::now(config('app.timezone'));

        return WorkAreaResource::collection(WorkArea::query()
            ->with('types')
            ->where('company_id', $this->companyId())
            ->when(! $request->boolean('includeInactive'), fn ($query) => $query->where('is_active', true))
            ->withCount(['orders as bookings_today' => fn ($query) => $query
                ->where('status', '!=', WorkOrderStatus::CANCELLED)
                ->whereBetween('scheduled_at', [$today->startOfDay(), $today->endOfDay()])])
            ->orderBy('name')
            ->get());
    }

    public function store(Request $request, SaveNamedEntryAction $save): JsonResponse
    {
        $area = $this->save(new WorkArea, $request, $save);

        return (new WorkAreaResource($area))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $workArea, SaveNamedEntryAction $save): WorkAreaResource
    {
        return new WorkAreaResource($this->save($this->owned(WorkArea::class, $workArea), $request, $save));
    }

    /** Saves the area and its types together. */
    private function save(WorkArea $area, Request $request, SaveNamedEntryAction $save): WorkArea
    {
        [$attributes, $types] = $this->validated($request, $area->exists ? $area : null);

        return DB::transaction(function () use ($area, $attributes, $types, $request, $save): WorkArea {
            $saved = $save->execute($area, $attributes, $request->user());
            if ($types) {
                $this->syncTypes($saved, $types, $request->user());
            }

            return $saved->load('types');
        });
    }

    /** @param Collection<int, AreaType> $types */
    private function syncTypes(WorkArea $area, Collection $types, User $actor): void
    {
        $before = $area->types()->pluck('name')->all();
        $area->types()->sync($types->mapWithKeys(fn (AreaType $type) => [$type->id => ['company_id' => $area->company_id]])->all());
        $after = $types->pluck('name')->all();
        if ($before !== $after) {
            app(AuditRecorder::class)->record($actor, app(CompanyContext::class)->company(), 'carwash.WorkArea.types_updated', $area, ['types' => $before], ['types' => $after]);
        }
    }

    /** @return array{0: array<string, mixed>, 1: Collection<int, AreaType>|null} */
    private function validated(Request $request, ?WorkArea $area): array
    {
        $required = $area ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$required, 'string', 'max:80'],
            'areaTypePublicIds' => [$required, 'array', 'min:1', 'max:2'],
            'areaTypePublicIds.*' => ['uuid', 'distinct'],
            'capacity' => [$required, 'integer', 'min:1', 'max:50'],
            'opensAt' => [$required, 'date_format:H:i'],
            'closesAt' => [$required, 'date_format:H:i'],
            'isActive' => ['sometimes', 'boolean'],
        ], [
            'areaTypePublicIds.min' => 'Elige al menos un tipo de área.',
            'areaTypePublicIds.max' => 'Un área hace como máximo dos tipos.',
        ]);
        $opens = $data['opensAt'] ?? substr((string) $area?->opens_at, 0, 5);
        $closes = $data['closesAt'] ?? substr((string) $area?->closes_at, 0, 5);
        // A closing at or before the opening is on the next day (23:00–01:00).
        if ($closes === $opens) {
            throw ValidationException::withMessages(['closesAt' => 'La hora de cierre no puede ser igual a la de apertura.']);
        }

        $attributes = [];
        foreach (['name' => 'name', 'capacity' => 'capacity', 'opensAt' => 'opens_at', 'closesAt' => 'closes_at', 'isActive' => 'is_active'] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $attributes[$column] = $data[$input];
            }
        }
        $types = array_key_exists('areaTypePublicIds', $data)
            ? app(AreaTypeCatalog::class)->resolve($this->companyId(), $data['areaTypePublicIds'], 'areaTypePublicIds', $area?->types()->pluck('carwash_area_types.id')->all() ?? [])
            : null;

        return [$attributes, $types];
    }
}
