<?php

namespace App\Domains\FiscalDocuments\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_id', 'establishment_id', 'emission_point_id', 'document_type', 'current_number', 'is_active'])]
class DocumentSequence extends Model {}
