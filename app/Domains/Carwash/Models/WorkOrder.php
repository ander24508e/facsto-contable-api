<?php

namespace App\Domains\Carwash\Models;

use App\Domains\Carwash\Enums\WorkOrderSource;
use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\Concerns\HasPublicId;
use App\Domains\Customers\Models\Customer;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use App\Shared\Realtime\AnnouncesChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Work order OT-000001: one vehicle with its services and products, for a customer from Clientes or only a contact
 * (name and phone). Its charge is the FACSTO sale in sale_id; the billing state is read from it.
 */
#[Fillable([
    'company_id', 'number', 'code_prefix', 'source', 'vehicle_id', 'customer_id', 'contact_name', 'contact_phone', 'washer_id', 'status', 'scheduled_at', 'work_area_id', 'scheduled_end_at',
    'arrived_at', 'started_at', 'ready_at', 'delivered_at', 'cancelled_at', 'cancel_reason', 'notes', 'checklist', 'dirty', 'loyalty_free', 'evidence_hold', 'sale_id', 'created_by',
])]
class WorkOrder extends Model
{
    use AnnouncesChanges;

    public function liveResource(): string
    {
        return 'carwash-orders';
    }

    /** The Lavadora's own channel: only with the module on and one of its permissions. */
    public function liveModule(): ?string
    {
        return 'carwash';
    }

    use HasPublicId;

    protected $table = 'carwash_work_orders';

    public function code(): string
    {
        return ($this->code_prefix ?: 'OT').'-'.str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
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

    /** Bandeja de avisos («vehículo listo», recordatorio de cita). */
    public function notifications(): HasMany
    {
        return $this->hasMany(CarwashNotification::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WorkOrderLine::class);
    }

    /** Who the order is for: the customer's name, or the contact typed at the counter. */
    public function contactName(): string
    {
        return trim((string) ($this->customer?->legal_name ?: $this->contact_name));
    }

    /** The phone to reach them: the one typed for this order, else the customer's. */
    public function contactPhone(): ?string
    {
        return $this->contact_phone ?: $this->customer?->phone;
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'checklist' => 'array',
            'dirty' => 'boolean',
            'loyalty_free' => 'boolean',
            'source' => WorkOrderSource::class,
            'status' => WorkOrderStatus::class,
            'scheduled_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'arrived_at' => 'datetime',
            'started_at' => 'datetime',
            'ready_at' => 'datetime',
            'delivered_at' => 'datetime',
            'evidence_hold' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }
}
