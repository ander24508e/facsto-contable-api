<?php

namespace App\Domains\Companies\Fiscal\Models;

use App\Domains\Companies\Fiscal\Enums\EmissionType;
use App\Domains\Companies\Fiscal\Enums\RimpeType;
use App\Domains\Companies\Fiscal\Enums\SriEnvironment;
use App\Domains\Companies\Models\Company;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'accounting_required', 'special_taxpayer_number', 'withholding_agent_number', 'rimpe_type', 'sri_environment', 'emission_type', 'sri_submission_enabled'])]
class CompanyFiscalSettings extends Model
{
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    protected function casts(): array
    {
        return [
            'accounting_required' => 'boolean',
            'rimpe_type' => RimpeType::class,
            'sri_environment' => SriEnvironment::class,
            'emission_type' => EmissionType::class,
            'sri_submission_enabled' => 'boolean',
        ];
    }
}
