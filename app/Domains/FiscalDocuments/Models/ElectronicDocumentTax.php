<?php

namespace App\Domains\FiscalDocuments\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_id', 'electronic_document_id', 'tax_code', 'percentage_code', 'percentage', 'taxable_base', 'tax_amount'])]
class ElectronicDocumentTax extends Model {}
