<?php

namespace App\Domains\Companies\Models;

use App\Domains\Catalog\Models\Item;
use App\Domains\Certificates\Models\DigitalCertificate;
use App\Domains\Companies\Enums\CompanyStatus;
use App\Domains\Companies\Fiscal\Models\CompanyFiscalSettings;
use App\Domains\Customers\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['ruc', 'legal_name', 'commercial_name', 'main_address', 'email', 'phone', 'status'])]
class Company extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $company): void {
            $company->public_id ??= (string) Str::uuid7();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyMembership::class);
    }

    public function fiscalSettings(): HasOne
    {
        return $this->hasOne(CompanyFiscalSettings::class);
    }

    public function digitalCertificates(): HasMany
    {
        return $this->hasMany(DigitalCertificate::class);
    }

    public function establishments(): HasMany
    {
        return $this->hasMany(Establishment::class);
    }

    public function emissionPoints(): HasMany
    {
        return $this->hasMany(EmissionPoint::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_memberships')
            ->withPivot(['id', 'role', 'is_active', 'joined_at'])
            ->withTimestamps();
    }

    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
        ];
    }
}
