<?php

namespace App\Domains\Companies\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rol de una empresa. «Administrador» es el único fijo (is_fixed): nace con la empresa, puede todo y no se edita ni
 * se desactiva. Los demás los crea la empresa con sus permisos por módulo (company_role_permissions).
 */
#[Fillable(['company_id', 'name', 'notes', 'is_fixed', 'is_active'])]
class Role extends Model
{
    public const ADMINISTRATOR = 'Administrador';

    protected $table = 'company_roles';

    protected static function booted(): void
    {
        static::creating(function (self $role): void {
            $role->public_id ??= (string) Str::uuid7();
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(CompanyMembership::class, 'company_membership_roles', 'role_id', 'membership_id');
    }

    /** Administrador first, then by name. @param Builder<Role> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByDesc('is_fixed')->orderBy('name');
    }

    /** @return list<string> «módulo.acción» */
    public function permissionKeys(): array
    {
        return DB::table('company_role_permissions')->where('role_id', $this->id)->orderBy('permission')->pluck('permission')->all();
    }

    /** @param list<string> $keys */
    public function syncPermissions(array $keys): void
    {
        DB::table('company_role_permissions')->where('role_id', $this->id)->delete();
        DB::table('company_role_permissions')->insert(array_map(fn (string $key): array => [
            'company_id' => $this->company_id,
            'role_id' => $this->id,
            'permission' => $key,
        ], array_values(array_unique($keys))));
    }

    protected function casts(): array
    {
        return ['is_fixed' => 'boolean', 'is_active' => 'boolean'];
    }
}
