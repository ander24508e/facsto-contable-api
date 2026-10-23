<?php

namespace App\Domains\FiscalDocuments\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'electronic_document_id', 'item_code', 'auxiliary_code', 'description', 'quantity', 'unit_price', 'discount', 'subtotal', 'total'])]
class ElectronicDocumentLine extends Model
{
    public function taxes(): HasMany
    {
        return $this->hasMany(ElectronicDocumentLineTax::class);
    }
}
