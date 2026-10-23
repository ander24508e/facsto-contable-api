<?php

namespace App\Domains\FiscalDocuments\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_id', 'electronic_document_id', 'method_code', 'method_name', 'amount', 'term', 'time_unit', 'reference'])]
class ElectronicDocumentPaymentMethod extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'term' => 'decimal:2'];
    }
}
