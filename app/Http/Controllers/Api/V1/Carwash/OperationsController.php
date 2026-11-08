<?php

namespace App\Http\Controllers\Api\V1\Carwash;

use App\Domains\Carwash\Models\CarwashCategory;
use App\Domains\Carwash\Models\CarwashSetting;
use App\Domains\Carwash\Services\AgendaService;
use App\Domains\Carwash\Services\CarwashDashboard;
use App\Domains\Carwash\Services\Rates;
use App\Domains\Companies\Context\CompanyContext;
use App\Shared\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The car wash's day, the agenda by slots and work areas, and the opening hours. */
final class OperationsController extends CarwashController
{
    public function dashboard(Request $request, CompanyContext $context, CarwashDashboard $dashboard): JsonResponse
    {
        return response()->json(['data' => $dashboard->day($context->company(), $this->date($request))]);
    }

    public function agenda(Request $request, CompanyContext $context, AgendaService $agenda): JsonResponse
    {
        $date = $this->date($request);

        return response()->json(['data' => ['date' => $date->toDateString(), ...$agenda->day($context->company(), $date)]]);
    }

    public function settings(CompanyContext $context): JsonResponse
    {
        return response()->json(['data' => $this->presentSettings(CarwashSetting::for($context->company()))]);
    }

    public function updateSettings(Request $request, CompanyContext $context, AuditRecorder $audit): JsonResponse
    {
        $data = $request->validate([
            'openingTime' => ['required', 'date_format:H:i'],
            'closingTime' => ['required', 'date_format:H:i'],
            'slotMinutes' => ['required', 'integer', 'in:15,20,30,45,60,90,120'],
        ]);
        if ($data['closingTime'] <= $data['openingTime']) {
            throw ValidationException::withMessages(['closingTime' => 'La hora de cierre debe ser posterior a la de apertura.']);
        }
        $settings = CarwashSetting::for($context->company());
        $before = $this->presentSettings($settings);
        $settings->fill([
            'opening_time' => $data['openingTime'],
            'closing_time' => $data['closingTime'],
            'slot_minutes' => $data['slotMinutes'],
        ])->save();
        $audit->record($request->user(), $context->company(), 'carwash.settings.updated', $context->company(), $before, $this->presentSettings($settings));

        return response()->json(['data' => $this->presentSettings($settings)]);
    }

    /**
     * GET /carwash/categories — the catalog categories, which ones are turned on in the Lavadora (their services and
     * products show in Tarifas) and how many services and products each has.
     */
    public function categories(CompanyContext $context, Rates $rates): JsonResponse
    {
        return response()->json(['data' => $this->presentCategories($context, $rates)]);
    }

    /** PUT /carwash/categories — the full list of turned-on categories. Taking one off only hides it in Tarifas. */
    public function updateCategories(Request $request, CompanyContext $context, Rates $rates, AuditRecorder $audit): JsonResponse
    {
        $data = $request->validate(['categoryPublicIds' => ['present', 'array', 'max:500'], 'categoryPublicIds.*' => ['uuid', 'distinct']]);
        $company = $context->company();
        $ids = $company->categories()->whereIn('public_id', $data['categoryPublicIds'])->pluck('id')->all();
        if (count($ids) !== count($data['categoryPublicIds'])) {
            throw ValidationException::withMessages(['categoryPublicIds' => 'Una de las categorías no existe en la empresa.']);
        }
        $before = $rates->categoryIds($company->id);
        DB::transaction(function () use ($company, $ids): void {
            CarwashCategory::query()->where('company_id', $company->id)->whereNotIn('category_id', $ids)->delete();
            foreach ($ids as $id) {
                CarwashCategory::query()->firstOrCreate(['company_id' => $company->id, 'category_id' => $id]);
            }
        });
        $rates->sync($company->id);
        $audit->record($request->user(), $company, 'carwash.categories.updated', $company, ['categoryIds' => $before], ['categoryIds' => $ids]);

        return response()->json(['data' => $this->presentCategories($context, $rates)]);
    }

    /** @return list<array<string, mixed>> */
    private function presentCategories(CompanyContext $context, Rates $rates): array
    {
        $company = $context->company();
        $enabled = $rates->categoryIds($company->id);
        $counts = $company->items()->whereNull('managed_by')->whereNotNull('category_id')
            ->selectRaw('category_id, type, count(*) as total')->groupBy('category_id', 'type')->get()
            ->groupBy('category_id');

        return $company->categories()->orderBy('name')->get()->map(fn ($category): array => [
            'publicId' => $category->public_id,
            'name' => $category->name,
            'isActive' => (bool) $category->is_active,
            'enabled' => in_array($category->id, $enabled, true),
            'servicesCount' => (int) ($counts->get($category->id)?->firstWhere(fn ($row) => ($row->type?->value ?? $row->type) === 'SERVICE')?->total ?? 0),
            'productsCount' => (int) ($counts->get($category->id)?->firstWhere(fn ($row) => ($row->type?->value ?? $row->type) === 'PRODUCT')?->total ?? 0),
        ])->values()->all();
    }

    private function date(Request $request): CarbonImmutable
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        return isset($data['date']) ? CarbonImmutable::parse($data['date'], config('app.timezone')) : CarbonImmutable::now(config('app.timezone'));
    }

    /** @return array{openingTime: string, closingTime: string, slotMinutes: int} */
    private function presentSettings(CarwashSetting $settings): array
    {
        return [
            'openingTime' => substr((string) $settings->opening_time, 0, 5),
            'closingTime' => substr((string) $settings->closing_time, 0, 5),
            'slotMinutes' => (int) $settings->slot_minutes,
        ];
    }
}
