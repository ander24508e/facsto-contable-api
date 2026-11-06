<?php

namespace App\Domains\Carwash\Models\Concerns;

use Illuminate\Support\Str;

/** public_id UUID v7 on creation; the only identifier that leaves the API. */
trait HasPublicId
{
    protected static function bootHasPublicId(): void
    {
        static::creating(fn ($model) => $model->public_id ??= (string) Str::uuid7());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
