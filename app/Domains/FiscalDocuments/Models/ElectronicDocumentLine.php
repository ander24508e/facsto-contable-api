<?php

namespace App\Domains\FiscalDocuments\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'electronic_document_id', 'source_line_id', 'item_code', 'auxiliary_code', 'description', 'quantity', 'unit_price', 'discount', 'subtotal', 'price_total_without_tax', 'total'])]
class ElectronicDocumentLine extends Model
{
    public function taxes(): HasMany
    {
        return $this->hasMany(ElectronicDocumentLineTax::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'unit_price' => 'decimal:6',
            'discount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'price_total_without_tax' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }
}
