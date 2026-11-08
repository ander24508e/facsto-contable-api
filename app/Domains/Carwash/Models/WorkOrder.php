<?php

namespace App\Domains\Carwash\Models;

use App\Domains\Carwash\Enums\WorkOrderSource;
use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\Concerns\HasPublicId;
use App\Domains\Customers\Models\Customer;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Work order OT-000001. Its charge is the FACSTO sale in sale_id; the billing state is read from it. */
#[Fillable([
    'company_id', 'number', 'source', 'vehicle_id', 'customer_id', 'washer_id', 'status', 'scheduled_at', 'work_area_id', 'scheduled_end_at',
    'arrived_at', 'started_at', 'ready_at', 'delivered_at', 'cancelled_at', 'cancel_reason', 'notes', 'sale_id', 'created_by',
])]
class WorkOrder extends Model
{
    use HasPublicId;

    protected $table = 'carwash_work_orders';

    public function code(): string
    {
        return 'OT-'.str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function washer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'washer_id');
    }

    public function workArea(): BelongsTo
    {
        return $this->belongsTo(WorkArea::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WorkOrderLine::class);
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'source' => WorkOrderSource::class,
            'status' => WorkOrderStatus::class,
            'scheduled_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'arrived_at' => 'datetime',
            'started_at' => 'datetime',
            'ready_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
