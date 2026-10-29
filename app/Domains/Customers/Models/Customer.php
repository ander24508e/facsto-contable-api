<?php

namespace App\Domains\Customers\Models;

use App\Domains\Companies\Models\Company;
use App\Domains\Customers\Enums\IdentificationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['company_id', 'identification_type', 'identification', 'legal_name', 'commercial_name', 'email', 'additional_emails', 'phone', 'address', 'is_active'])]
class Customer extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $customer) => $customer->public_id ??= (string) Str::uuid7());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    protected function casts(): array
    {
        return ['identification_type' => IdentificationType::class, 'additional_emails' => 'array', 'is_active' => 'boolean'];
    }
}
