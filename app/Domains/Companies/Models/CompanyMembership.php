<?php

namespace App\Domains\Companies\Models;

use App\Domains\Companies\Enums\CompanyRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'user_id', 'role', 'is_active', 'joined_at', 'onboarding_completed_at'])]
class CompanyMembership extends Model
{
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'role' => CompanyRole::class,
            'is_active' => 'boolean',
            'joined_at' => 'datetime',
            'onboarding_completed_at' => 'immutable_datetime',
        ];
    }
}
