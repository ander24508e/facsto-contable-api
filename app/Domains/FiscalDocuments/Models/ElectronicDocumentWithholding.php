<?php

namespace App\Domains\FiscalDocuments\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One line of a comprobante de retención: tax (1 Renta, 2 IVA), code, base, percentage and amount withheld. */
#[Fillable(['company_id', 'electronic_document_id', 'tax_code', 'withholding_code', 'name', 'taxable_base', 'percentage', 'amount'])]
class ElectronicDocumentWithholding extends Model
{
    protected function casts(): array
    {
        return ['taxable_base' => 'decimal:2', 'percentage' => 'decimal:2', 'amount' => 'decimal:2'];
    }
}
