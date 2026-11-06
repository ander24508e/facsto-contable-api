<?php

namespace App\Domains\Modules\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** The last entitlements version applied to a company, with its plan limits. */
#[Fillable(['company_id', 'version', 'subscription_reference', 'limits', 'issued_at', 'synced_at'])]
class CompanyEntitlementState extends Model
{
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['version' => 'integer', 'limits' => 'array', 'issued_at' => 'immutable_datetime', 'synced_at' => 'immutable_datetime'];
    }
}
