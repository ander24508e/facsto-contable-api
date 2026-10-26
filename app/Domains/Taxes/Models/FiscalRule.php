<?php

namespace App\Domains\Taxes\Models;

use App\Domains\Taxes\Enums\FiscalRuleCode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'value_type', 'value_decimal', 'value_string', 'valid_from', 'valid_until', 'legal_basis', 'is_active'])]
class FiscalRule extends Model
{
    protected function casts(): array
    {
        return [
            'code' => FiscalRuleCode::class,
            'value_decimal' => 'decimal:6',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
