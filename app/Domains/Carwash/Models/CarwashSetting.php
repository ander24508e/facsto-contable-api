<?php

namespace App\Domains\Carwash\Models;

use App\Domains\Catalog\Models\Item;
use App\Domains\Companies\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuración de la Lavadora, una pestaña por submódulo (Agenda, Servicios, Órdenes, Áreas, Vehículos, Tarifas,
 * Trabajadores, Insumos y Clientes). Los valores por defecto dejan el módulo como era antes de cada ajuste.
 */
class CarwashSetting extends Model
{
    public const BILLING_MANUAL = 'MANUAL';

    public const BILLING_AUTO = 'AUTO';

    public const PRICING_BY_VEHICLE = 'BY_VEHICLE';

    public const PRICING_SINGLE = 'SINGLE';

    protected $table = 'carwash_settings';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    public static function for(Company $company): self
    {
        return self::forCompany($company->id);
    }

    public static function forCompany(int $companyId): self
    {
        return self::query()->firstOrCreate(['company_id' => $companyId], [
            'opening_time' => '08:00', 'closing_time' => '18:00', 'slot_minutes' => 30,
        ])->refresh();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function dirtySurcharge(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'dirty_surcharge_item_id');
    }

    public function peakSurcharge(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'peak_surcharge_item_id');
    }

    /** @return list<int> ISO weekdays (1 = lunes … 7 = domingo) the car wash is closed */
    public function closedWeekdays(): array
    {
        return array_map('intval', $this->closed_weekdays ?? []);
    }

    /** @return list<string> Y-m-d */
    public function closedDates(): array
    {
        return array_values($this->closed_dates ?? []);
    }

    /** @return list<string> */
    public function checklistItems(): array
    {
        return array_values($this->reception_checklist ?? []);
    }

    protected function casts(): array
    {
        return [
            'slot_minutes' => 'integer',
            'min_advance_minutes' => 'integer',
            'max_advance_days' => 'integer',
            'arrival_tolerance_minutes' => 'integer',
            'vehicles_per_slot' => 'integer',
            'closed_weekdays' => 'array',
            'closed_dates' => 'array',
            'reception_checklist' => 'array',
            'require_checklist' => 'boolean',
            'use_arrived_step' => 'boolean',
            'use_ready_step' => 'boolean',
            'order_start_number' => 'integer',
            'allow_unbilled_delivery' => 'boolean',
            'cleanup_minutes' => 'integer',
            'one_vehicle_per_area' => 'boolean',
            'auto_assign_area' => 'boolean',
            'require_color' => 'boolean',
            'require_brand_model' => 'boolean',
            'require_year' => 'boolean',
            'validate_ec_plate' => 'boolean',
            'rounding_step' => 'decimal:2',
            'commission_value' => 'decimal:2',
            'max_orders_per_washer' => 'integer',
            'supply_alert' => 'boolean',
            'notify_ready' => 'boolean',
            'notify_reminder' => 'boolean',
            'reminder_hours' => 'integer',
            'loyalty_every' => 'integer',
            'setup_completed_at' => 'datetime',
        ];
    }
}
