<?php

namespace App\Http\Controllers\Api\V1\Carwash;

use App\Domains\Carwash\Models\CarwashCategory;
use App\Domains\Carwash\Models\CarwashSetting;
use App\Domains\Carwash\Services\AgendaService;
use App\Domains\Carwash\Services\CarwashDashboard;
use App\Domains\Carwash\Services\CarwashReports;
use App\Domains\Carwash\Services\CarwashSettingsForm;
use App\Domains\Carwash\Services\Rates;
use App\Domains\Companies\Context\CompanyContext;
use App\Shared\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The car wash's day, the agenda by slots and work areas, and Configuración: its settings (one tab per submodule),
 * the first-time assistant («Pendiente por configurar»), the supplies and the washers' commissions.
 */
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

    public function settings(CompanyContext $context, CarwashSettingsForm $form): JsonResponse
    {
        return response()->json(['data' => $form->present(CarwashSetting::for($context->company()))]);
    }

    /** PUT /carwash/settings — each tab sends only its own fields. */
    public function updateSettings(Request $request, CompanyContext $context, AuditRecorder $audit, CarwashSettingsForm $form): JsonResponse
    {
        $data = $request->validate($form->rules());
        $settings = CarwashSetting::for($context->company());
        $before = $form->present($settings);
        $form->apply($settings, $data);
        $settings->touch();
        $settings->save();
        $after = $form->present($settings->refresh());
        $audit->record($request->user(), $context->company(), 'carwash.settings.updated', $context->company(),
            array_intersect_key($before, $after), array_filter($after, fn ($value, $key) => ($before[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));

        return response()->json(['data' => $after]);
    }

    /** GET /carwash/settings/pending — the first-time assistant and «Pendiente por configurar». */
    public function pending(CompanyContext $context, CarwashReports $reports, CarwashSettingsForm $form): JsonResponse
    {
        $settings = CarwashSetting::for($context->company());

        return response()->json(['data' => [
            'setupCompletedAt' => $form->present($settings)['setupCompletedAt'],
            'items' => $reports->pending($context->company()),
        ]]);
    }

    /** POST /carwash/settings/setup-complete — the assistant is not shown again. */
    public function completeSetup(Request $request, CompanyContext $context, AuditRecorder $audit, CarwashSettingsForm $form): JsonResponse
    {
        $settings = CarwashSetting::for($context->company());
        if ($settings->setup_completed_at === null) {
            $settings->forceFill(['setup_completed_at' => now()])->save();
            $audit->record($request->user(), $context->company(), 'carwash.settings.setup_completed', $context->company(), null, ['setupCompletedAt' => $settings->setup_completed_at->toIso8601String()]);
        }

        return response()->json(['data' => $form->present($settings)]);
    }

    /** GET /carwash/supplies — Configuración › Insumos. */
    public function supplies(CompanyContext $context, CarwashReports $reports): JsonResponse
    {
        return response()->json(['data' => $reports->supplies($context->company())]);
    }

    /** GET /carwash/reports?from=&to= — Órdenes › «Reportes» (the current month by default); commissions only with their permission. */
    public function reports(Request $request, CarwashReports $reports): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $today = CarbonImmutable::now(config('app.timezone'));
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'], config('app.timezone')) : $today->startOfMonth();
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'], config('app.timezone')) : $today;

        return response()->json(['data' => [
            ...$reports->report($this->company(), $from, $to),
            'commissions' => Gate::allows('carwash.commissions') ? $reports->commissions($this->company(), $from, $to) : null,
        ]]);
    }

    /** GET /carwash/commissions?from=&to= — Configuración › Trabajadores «Comisiones» (the current month by default). */
    public function commissions(Request $request, CompanyContext $context, CarwashReports $reports): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $today = CarbonImmutable::now(config('app.timezone'));
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'], config('app.timezone')) : $today->startOfMonth();
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'], config('app.timezone')) : $today;

        return response()->json(['data' => $reports->commissions($context->company(), $from, $to)]);
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
}
