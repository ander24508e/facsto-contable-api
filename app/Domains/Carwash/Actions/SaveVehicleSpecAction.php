<?php

namespace App\Domains\Carwash\Actions;

use App\Domains\Carwash\Models\VehicleBrand;
use App\Domains\Carwash\Models\VehicleModel;
use App\Domains\Carwash\Models\VehicleType;
use App\Domains\Companies\Context\CompanyContext;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A vehicle specification (brand + model + type) written by name: an existing brand or type is reused,
 * ignoring case and spaces (and reactivated if it was off), a new one is created. The combination never repeats.
 */
final class SaveVehicleSpecAction
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditRecorder $audit) {}

    /** @param array{brand: string, model: string, type: string} $names */
    public function create(array $names, User $actor): VehicleModel
    {
        $company = $this->context->company();
        [$brandName, $modelName, $typeName] = array_map(fn (string $name): string => trim((string) preg_replace('/\s+/u', ' ', $name)), [$names['brand'], $names['model'], $names['type']]);

        return DB::transaction(function () use ($company, $brandName, $modelName, $typeName, $actor): VehicleModel {
            $brand = $this->named(new VehicleBrand, $company->id, $brandName);
            $type = $this->named(new VehicleType, $company->id, $typeName);
            $exists = VehicleModel::query()->where('vehicle_brand_id', $brand->id)->where('vehicle_type_id', $type->id)
                ->whereRaw('lower(name) = ?', [mb_strtolower($modelName)])->exists();
            if ($exists) {
                throw new ApiException(ApiErrorCode::CARWASH_DUPLICATE_NAME, 'Ya existe esa especificación.', 409, ['errors' => ['model' => ['Ya existe esa especificación.']]]);
            }

            $spec = VehicleModel::query()->create([
                'company_id' => $company->id, 'vehicle_brand_id' => $brand->id, 'vehicle_type_id' => $type->id, 'name' => $modelName, 'is_active' => true,
            ]);
            $this->audit->record($actor, $company, 'carwash.VehicleModel.created', $spec, null, ['brand' => $brandName, 'model' => $modelName, 'type' => $typeName]);

            return $spec->load(['brand', 'type']);
        });
    }

    /**
     * Renames the model, changes its type (by name, like create) or turns it on or off. Its vehicles take the new
     * type, because the type sets their price.
     *
     * @param  array{model?: string, type?: string, isActive?: bool}  $data
     */
    public function update(VehicleModel $spec, array $data, User $actor): VehicleModel
    {
        $company = $this->context->company();

        return DB::transaction(function () use ($spec, $data, $actor, $company): VehicleModel {
            $before = $spec->only(['name', 'vehicle_type_id', 'is_active']);
            $attributes = [];
            if (array_key_exists('model', $data)) {
                $attributes['name'] = $this->clean($data['model']);
            }
            if (array_key_exists('type', $data)) {
                $attributes['vehicle_type_id'] = $this->named(new VehicleType, $company->id, $this->clean($data['type']))->id;
            }
            if (array_key_exists('isActive', $data)) {
                $attributes['is_active'] = (bool) $data['isActive'];
            }

            $name = $attributes['name'] ?? $spec->name;
            $typeId = $attributes['vehicle_type_id'] ?? $spec->vehicle_type_id;
            $exists = VehicleModel::query()->whereKeyNot($spec->id)->where('vehicle_brand_id', $spec->vehicle_brand_id)
                ->where('vehicle_type_id', $typeId)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists();
            if ($exists) {
                throw new ApiException(ApiErrorCode::CARWASH_DUPLICATE_NAME, 'Ya existe esa especificación.', 409, ['errors' => ['model' => ['Ya existe esa especificación.']]]);
            }

            $spec->forceFill($attributes)->save();
            if ($spec->wasChanged('vehicle_type_id')) {
                $spec->vehicles()->update(['vehicle_type_id' => $typeId]);
            }
            $this->audit->record($actor, $company, 'carwash.VehicleModel.updated', $spec, $before, $spec->only(array_keys($attributes)));

            return $spec->load(['brand', 'type']);
        });
    }

    private function clean(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @return TModel
     */
    private function named(Model $model, int $companyId, string $name): Model
    {
        $found = $model->newQuery()->where('company_id', $companyId)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
        if ($found) {
            if (! $found->is_active) {
                $found->forceFill(['is_active' => true])->save();
            }

            return $found;
        }

        return $model->newQuery()->create(['company_id' => $companyId, 'name' => $name, 'is_active' => true]);
    }
}
