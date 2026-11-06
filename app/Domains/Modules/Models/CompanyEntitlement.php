<?php

namespace App\Domains\Modules\Models;

use App\Domains\Companies\Models\Company;
use App\Domains\Modules\Enums\EntitlementStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A module or module feature of a company ("carwash", "carwash.storefront"…) and its state. */
#[Fillable(['company_id', 'feature', 'status', 'source', 'valid_until'])]
class CompanyEntitlement extends Model
{
    /** timestampTz columns keep their offset, so PostgreSQL (UTC) never reads local time as UTC. */
    protected $dateFormat = 'Y-m-d H:i:sP';

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** The state that applies now: an expired entitlement only reads. */
    public function effectiveStatus(): EntitlementStatus
    {
        if ($this->status === EntitlementStatus::ENABLED && $this->valid_until?->isPast()) {
            return EntitlementStatus::READ_ONLY;
        }

        return $this->status;
    }

    protected function casts(): array
    {
        return ['status' => EntitlementStatus::class, 'valid_until' => 'immutable_datetime'];
    }
}
