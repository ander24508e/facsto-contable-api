<?php

namespace App\Domains\Carwash\Actions;

use App\Domains\Carwash\Models\Service;
use App\Domains\Carwash\Services\ServicePriceItems;
use App\Domains\Companies\Context\CompanyContext;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Creates or edits a global service. Its name, description, category, IVA and state reach the Inventario items
 * of its prices per vehicle at once.
 */
final class SaveServiceAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly ServicePriceItems $items,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $data name, description, categoryPublicId, taxPublicId, durationMinutes, isFeatured, isBookable, isActive */
    public function execute(Service $service, array $data, User $actor): Service
    {
        $company = $this->context->company();
        $attributes = [];

        if (array_key_exists('name', $data)) {
            $attributes['name'] = trim((string) preg_replace('/\s+/u', ' ', (string) $data['name']));
            $taken = Service::query()->where('company_id', $company->id)->whereRaw('lower(name) = ?', [mb_strtolower($attributes['name'])])
                ->when($service->exists, fn ($query) => $query->whereKeyNot($service->id))->exists();
            if ($taken) {
                throw new ApiException(ApiErrorCode::CARWASH_DUPLICATE_NAME, 'Ya existe un servicio con ese nombre.', 409, ['errors' => ['name' => ['Ya existe un servicio con ese nombre.']]]);
            }
        }
        if (array_key_exists('description', $data)) {
            $attributes['description'] = trim((string) $data['description']) ?: null;
        }
        if (array_key_exists('categoryPublicId', $data)) {
            $attributes['category_id'] = $data['categoryPublicId'] === null ? null
                : ($company->categories()->where('public_id', $data['categoryPublicId'])->where('is_active', true)->first()?->id
                    ?? throw new ApiException(ApiErrorCode::CATEGORY_NOT_FOUND, 'La categoría no está activa en la empresa actual.', 422, ['errors' => ['categoryPublicId' => ['La categoría no está activa.']]]));
        }
        if (array_key_exists('taxPublicId', $data)) {
            $tax = $company->taxes()->where('public_id', $data['taxPublicId'])->with('definition')->first();
            if (! $tax || ! $tax->isUsableOn(today()) || $tax->definition->tax_type->value !== 'IVA') {
                throw new ApiException(ApiErrorCode::TAX_NOT_AVAILABLE, 'Elige una tarifa de IVA vigente.', 422, ['errors' => ['taxPublicId' => ['Elige una tarifa de IVA vigente.']]]);
            }
            $attributes['company_tax_id'] = $tax->id;
        }
        foreach (['durationMinutes' => 'duration_minutes', 'isFeatured' => 'is_featured', 'isBookable' => 'is_bookable', 'isActive' => 'is_active'] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $attributes[$column] = $data[$input];
            }
        }

        return DB::transaction(function () use ($service, $attributes, $actor, $company): Service {
            $before = $service->exists ? $service->only(array_keys($attributes)) : null;
            $isNew = ! $service->exists;
            $service->fill([...$attributes, 'company_id' => $company->id])->save();
            if (! $isNew && $service->wasChanged(['name', 'description', 'category_id', 'company_tax_id', 'is_active'])) {
                $this->items->refreshService($service, $actor);
            }
            $this->audit->record($actor, $company, $isNew ? 'carwash.service.created' : 'carwash.service.updated', $service, $before, $service->only(array_keys($attributes)));

            return $service->refresh();
        });
    }
}
