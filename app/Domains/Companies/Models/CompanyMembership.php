<?php

namespace App\Domains\Companies\Models;

use App\Models\User;
use App\Shared\Realtime\AnnouncesChanges;
use App\Shared\Realtime\Realtime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Un usuario en su empresa, con uno o varios roles (sus permisos se suman). */
#[Fillable(['company_id', 'user_id', 'is_active', 'joined_at', 'onboarding_completed_at'])]
class CompanyMembership extends Model
{
    use AnnouncesChanges;

    public function liveResource(): string
    {
        return 'users';
    }

    /** Regla 31: the member's access changed (activated, deactivated): their open tabs adjust too. */
    protected function announceChange(string $action): void
    {
        app(Realtime::class)->forCompanyId((int) $this->company_id, 'users', app(Realtime::class)->publicIdOf(User::class, $this->user_id), $action, $this->updated_at);
        if ($this->user) {
            app(Realtime::class)->user($this->user, 'access');
        }
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'company_membership_roles', 'membership_id', 'role_id')
            ->orderByDesc('company_roles.is_fixed')
            ->orderBy('company_roles.name');
    }

    /** @param list<int> $roleIds */
    public function syncRoles(array $roleIds): void
    {
        $this->roles()->sync(collect($roleIds)->unique()->mapWithKeys(fn (int $id) => [$id => ['company_id' => $this->company_id]])->all());
        $this->unsetRelation('roles');
        // Regla 31: their roles changed; their open tabs reload their permissions.
        $this->announceChange('updated');
    }

    public function isAdministrator(): bool
    {
        return $this->roles()->where('company_roles.is_fixed', true)->exists();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'joined_at' => 'datetime',
            'onboarding_completed_at' => 'immutable_datetime',
        ];
    }
}
