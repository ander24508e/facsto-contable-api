<?php

namespace App\Domains\Sales\Models;

use App\Domains\Catalog\Models\FiscalPaymentMethod;
use App\Shared\Realtime\AnnouncesChanges;
use App\Shared\Realtime\Realtime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'sale_id', 'fiscal_payment_method_id', 'method_code', 'method_name', 'amount', 'reference', 'term', 'time_unit', 'is_credit', 'down_payment', 'installment_frequency', 'credit_notes'])]
class SalePaymentMethod extends Model
{
    use AnnouncesChanges;

    public function liveResource(): string
    {
        return 'sales';
    }

    /** It announces its sale. */
    public function livePublicId(): ?string
    {
        return app(Realtime::class)->publicIdOf(Sale::class, $this->sale_id);
    }

    public function liveAction(string $action): string
    {
        return 'updated';
    }

    public function fiscalPaymentMethod(): BelongsTo
    {
        return $this->belongsTo(FiscalPaymentMethod::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'term' => 'decimal:2', 'is_credit' => 'boolean', 'down_payment' => 'decimal:2'];
    }
}
