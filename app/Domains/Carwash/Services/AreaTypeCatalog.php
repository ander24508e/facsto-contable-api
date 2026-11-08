<?php

namespace App\Domains\Carwash\Services;

use App\Domains\Carwash\Models\AreaType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The company's area types. Lavado, Secado and Lubricación are created the first time they are needed
 * (insertOrIgnore: two requests at once never duplicate them).
 */
final class AreaTypeCatalog
{
    public function ensureFixed(int $companyId): void
    {
        if (AreaType::query()->where('company_id', $companyId)->whereNotNull('code')->count() === count(AreaType::FIXED)) {
            return;
        }
        $now = now();
        AreaType::query()->insertOrIgnore(array_map(fn (string $code, string $name): array => [
            'public_id' => (string) Str::uuid7(),
            'company_id' => $companyId,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_keys(AreaType::FIXED), AreaType::FIXED));
    }

    /**
     * The types with these public ids. Each must be the company's and active, unless it is one the record already
     * has ($current): an area or a service keeps a type that was turned off later.
     *
     * @param  list<string>  $publicIds
     * @param  list<int>  $current
     * @return Collection<int, AreaType>
     */
    public function resolve(int $companyId, array $publicIds, string $field, array $current = []): Collection
    {
        $this->ensureFixed($companyId);
        $types = AreaType::query()->where('company_id', $companyId)->whereIn('public_id', $publicIds)
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $current ?: [0]))
            ->ordered()->get();
        if ($types->count() !== count(array_unique($publicIds))) {
            throw ValidationException::withMessages([$field => 'El tipo de área no existe o está inactivo.']);
        }

        return $types;
    }
}
