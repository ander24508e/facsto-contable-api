<?php

namespace App\Domains\Modules\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Append-only log of every entitlements message received (applied or stale). */
#[Fillable(['company_id', 'version', 'source', 'result', 'payload', 'created_at'])]
class EntitlementSync extends Model
{
    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['version' => 'integer', 'payload' => 'array'];
    }
}
