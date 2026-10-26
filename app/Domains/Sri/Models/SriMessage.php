<?php

namespace App\Domains\Sri\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_id', 'submission_id', 'identifier', 'type', 'message', 'additional_information'])]
class SriMessage extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
