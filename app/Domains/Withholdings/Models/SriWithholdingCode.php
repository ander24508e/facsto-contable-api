<?php

namespace App\Domains\Withholdings\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** A retention code of the SRI: Renta (ATS concepts) or IVA, with its suggested percentage. */
#[Fillable(['tax', 'code', 'name', 'percentage', 'percentage_editable', 'is_active'])]
class SriWithholdingCode extends Model
{
    public const RENTA = 'RENTA';

    public const IVA = 'IVA';

    protected static function booted(): void
    {
        static::creating(fn (self $code) => $code->public_id ??= (string) Str::uuid7());
    }

    /** codigo of the XML: 1 Renta, 2 IVA. */
    public function sriTaxCode(): string
    {
        return $this->tax === self::IVA ? '2' : '1';
    }

    protected function casts(): array
    {
        return ['percentage' => 'decimal:2', 'percentage_editable' => 'boolean', 'is_active' => 'boolean'];
    }
}
