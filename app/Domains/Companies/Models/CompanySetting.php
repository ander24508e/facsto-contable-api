<?php

namespace App\Domains\Companies\Models;

use App\Domains\Taxes\Models\CompanyTax;
use App\Shared\Realtime\AnnouncesChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sistema: the rules and defaults of one company (stock threshold, sale and product defaults, idle timeout). */
#[Fillable(['company_id', 'low_stock_threshold', 'allow_negative_stock', 'default_payment_method', 'default_customer', 'default_company_tax_id', 'default_unit', 'idle_timeout_minutes', 'sales_interface', 'proformas_interface', 'investments_interface', 'module_interfaces'])]
class CompanySetting extends Model
{
    use AnnouncesChanges;

    public function liveResource(): string
    {
        return 'settings';
    }

    public const DEFAULT_CUSTOMERS = ['EMPTY', 'FINAL_CONSUMER'];

    /** Minutes the idle timeout can take; 0 never closes the session. */
    public const IDLE_TIMEOUTS = [0, 15, 30, 60, 480];

    protected $attributes = [
        'low_stock_threshold' => 5,
        'allow_negative_stock' => false,
        'default_customer' => 'EMPTY',
        'idle_timeout_minutes' => 0,
        'sales_interface' => 'LIST',
        'proformas_interface' => 'LIST',
        'investments_interface' => 'LIST',
        // Raw (stored) value: the array cast decodes it.
        'module_interfaces' => '[]',
    ];

    public function defaultTax(): BelongsTo
    {
        return $this->belongsTo(CompanyTax::class, 'default_company_tax_id');
    }

    protected function casts(): array
    {
        return ['low_stock_threshold' => 'integer', 'allow_negative_stock' => 'boolean', 'idle_timeout_minutes' => 'integer', 'module_interfaces' => 'array'];
    }
}
