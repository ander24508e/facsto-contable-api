<?php

namespace App\Domains\FiscalDocuments\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_id', 'establishment_id', 'emission_point_id', 'document_type', 'issuer_ruc', 'current_number', 'initial_number', 'is_active'])]
class DocumentSequence extends Model
{
    protected function casts(): array
    {
        return ['current_number' => 'integer', 'initial_number' => 'integer', 'is_active' => 'boolean'];
    }
}
