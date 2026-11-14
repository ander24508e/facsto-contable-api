<?php

namespace App\Domains\Carwash\Services;

use App\Domains\Carwash\Models\CarwashSetting;
use App\Domains\Catalog\Enums\ItemType;
use App\Domains\Catalog\Models\Item;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * GET / PUT /carwash/settings. Each tab of Configuración sends only its own fields (all `sometimes`); the answer is
 * always the whole configuration. The surcharges are Inventario services, sent by their public id.
 */
final class CarwashSettingsForm
{
    /** camelCase field → column, for the plain values. */
    private const COLUMNS = [
        'openingTime' => 'opening_time', 'closingTime' => 'closing_time', 'slotMinutes' => 'slot_minutes',
        'minAdvanceMinutes' => 'min_advance_minutes', 'maxAdvanceDays' => 'max_advance_days',
        'arrivalToleranceMinutes' => 'arrival_tolerance_minutes', 'vehiclesPerSlot' => 'vehicles_per_slot',
        'closedWeekdays' => 'closed_weekdays', 'closedDates' => 'closed_dates',
        'receptionChecklist' => 'reception_checklist', 'requireChecklist' => 'require_checklist',
        'useArrivedStep' => 'use_arrived_step', 'useReadyStep' => 'use_ready_step',
        'orderPrefix' => 'order_prefix', 'orderStartNumber' => 'order_start_number', 'billingMode' => 'billing_mode',
        'allowUnbilledDelivery' => 'allow_unbilled_delivery',
        'cleanupMinutes' => 'cleanup_minutes', 'oneVehiclePerArea' => 'one_vehicle_per_area', 'autoAssignArea' => 'auto_assign_area',
        'requireColor' => 'require_color', 'requireBrandModel' => 'require_brand_model', 'requireYear' => 'require_year',
        'validateEcPlate' => 'validate_ec_plate',
        'pricingMode' => 'pricing_mode', 'peakFrom' => 'peak_from', 'peakTo' => 'peak_to', 'roundingStep' => 'rounding_step',
        'commissionType' => 'commission_type', 'commissionValue' => 'commission_value', 'assignmentMode' => 'assignment_mode',
        'maxOrdersPerWasher' => 'max_orders_per_washer',
        'consumeSuppliesOn' => 'consume_supplies_on', 'supplyAlert' => 'supply_alert',
        'notifyReady' => 'notify_ready', 'notifyReminder' => 'notify_reminder', 'reminderHours' => 'reminder_hours',
        'loyaltyEvery' => 'loyalty_every',
    ];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $money = 'regex:/^\d{1,8}(\.\d{1,2})?$/';

        return [
            'openingTime' => ['sometimes', 'date_format:H:i'],
            'closingTime' => ['sometimes', 'date_format:H:i'],
            'slotMinutes' => ['sometimes', 'integer', 'in:15,20,30,45,60,90,120'],
            'minAdvanceMinutes' => ['sometimes', 'integer', 'min:0', 'max:10080'],
            'maxAdvanceDays' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'arrivalToleranceMinutes' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:240'],
            'vehiclesPerSlot' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
            'closedWeekdays' => ['sometimes', 'array', 'max:7'],
            'closedWeekdays.*' => ['integer', 'between:1,7', 'distinct'],
            'closedDates' => ['sometimes', 'array', 'max:366'],
            'closedDates.*' => ['date_format:Y-m-d', 'distinct'],
            'receptionChecklist' => ['sometimes', 'array', 'max:30'],
            'receptionChecklist.*' => ['string', 'min:2', 'max:80', 'distinct'],
            'requireChecklist' => ['sometimes', 'boolean'],
            'useArrivedStep' => ['sometimes', 'boolean'],
            'useReadyStep' => ['sometimes', 'boolean'],
            'orderPrefix' => ['sometimes', 'string', 'regex:/^[A-Za-z]{1,6}$/'],
            'orderStartNumber' => ['sometimes', 'integer', 'min:1', 'max:999999'],
            'billingMode' => ['sometimes', Rule::in([CarwashSetting::BILLING_MANUAL, CarwashSetting::BILLING_AUTO])],
            'allowUnbilledDelivery' => ['sometimes', 'boolean'],
            'cleanupMinutes' => ['sometimes', 'integer', 'min:0', 'max:120'],
            'oneVehiclePerArea' => ['sometimes', 'boolean'],
            'autoAssignArea' => ['sometimes', 'boolean'],
            'requireColor' => ['sometimes', 'boolean'],
            'requireBrandModel' => ['sometimes', 'boolean'],
            'requireYear' => ['sometimes', 'boolean'],
            'validateEcPlate' => ['sometimes', 'boolean'],
            'pricingMode' => ['sometimes', Rule::in([CarwashSetting::PRICING_BY_VEHICLE, CarwashSetting::PRICING_SINGLE])],
            'dirtySurchargeItemPublicId' => ['sometimes', 'nullable', 'uuid'],
            'peakSurchargeItemPublicId' => ['sometimes', 'nullable', 'uuid'],
            'peakFrom' => ['sometimes', 'nullable', 'date_format:H:i'],
            'peakTo' => ['sometimes', 'nullable', 'date_format:H:i'],
            'roundingStep' => ['sometimes', 'nullable', 'in:0.05,0.10,0.25,0.50,1.00'],
            'commissionType' => ['sometimes', 'in:PERCENT,FIXED'],
            'commissionValue' => ['sometimes', 'string', $money],
            'assignmentMode' => ['sometimes', 'in:MANUAL,ROTATION'],
            'maxOrdersPerWasher' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
            'consumeSuppliesOn' => ['sometimes', 'in:READY,DELIVERED'],
            'supplyAlert' => ['sometimes', 'boolean'],
            'notifyReady' => ['sometimes', 'boolean'],
            'notifyReminder' => ['sometimes', 'boolean'],
            'reminderHours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'loyaltyEvery' => ['sometimes', 'nullable', 'integer', 'min:2', 'max:100'],
        ];
    }

    /** @param array<string, mixed> $data validated */
    public function apply(CarwashSetting $settings, array $data): void
    {
        foreach (self::COLUMNS as $field => $column) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                if ($field === 'orderPrefix') {
                    $value = strtoupper((string) $value);
                } elseif ($field === 'receptionChecklist') {
                    $value = array_values(array_map(fn ($label) => trim((string) $label), $value));
                } elseif (in_array($field, ['closedWeekdays', 'closedDates'], true)) {
                    $value = array_values($value);
                    sort($value);
                }
                $settings->{$column} = $value;
            }
        }
        foreach (['dirtySurchargeItemPublicId' => 'dirty_surcharge_item_id', 'peakSurchargeItemPublicId' => 'peak_surcharge_item_id'] as $field => $column) {
            if (array_key_exists($field, $data)) {
                $settings->{$column} = $data[$field] === null ? null : $this->surchargeItem($settings->company_id, $data[$field], $field)->id;
            }
        }

        $opening = substr((string) $settings->opening_time, 0, 5);
        $closing = substr((string) $settings->closing_time, 0, 5);
        if ($closing <= $opening) {
            throw ValidationException::withMessages(['closingTime' => 'La hora de cierre debe ser posterior a la de apertura.']);
        }
        if (($settings->peak_from === null) !== ($settings->peak_to === null)
            || ($settings->peak_from !== null && substr((string) $settings->peak_to, 0, 5) <= substr((string) $settings->peak_from, 0, 5))) {
            throw ValidationException::withMessages(['peakTo' => 'La hora pico necesita un inicio y un fin posterior.']);
        }
        if ($settings->peak_surcharge_item_id !== null && $settings->peak_from === null) {
            throw ValidationException::withMessages(['peakFrom' => 'Indica desde y hasta qué hora es la hora pico.']);
        }
    }

    /** @return array<string, mixed> */
    public function present(CarwashSetting $settings): array
    {
        $settings->loadMissing(['dirtySurcharge', 'peakSurcharge']);
        $time = fn ($value): ?string => $value === null ? null : substr((string) $value, 0, 5);
        $item = fn (?Item $item): ?array => $item ? ['publicId' => $item->public_id, 'name' => $item->name] : null;

        return [
            'openingTime' => $time($settings->opening_time),
            'closingTime' => $time($settings->closing_time),
            'slotMinutes' => (int) $settings->slot_minutes,
            'minAdvanceMinutes' => (int) $settings->min_advance_minutes,
            'maxAdvanceDays' => (int) $settings->max_advance_days,
            'arrivalToleranceMinutes' => $settings->arrival_tolerance_minutes,
            'vehiclesPerSlot' => $settings->vehicles_per_slot,
            'closedWeekdays' => $settings->closedWeekdays(),
            'closedDates' => $settings->closedDates(),
            'receptionChecklist' => $settings->checklistItems(),
            'requireChecklist' => (bool) $settings->require_checklist,
            'useArrivedStep' => (bool) $settings->use_arrived_step,
            'useReadyStep' => (bool) $settings->use_ready_step,
            'orderPrefix' => $settings->order_prefix,
            'orderStartNumber' => (int) $settings->order_start_number,
            'billingMode' => $settings->billing_mode,
            'allowUnbilledDelivery' => (bool) $settings->allow_unbilled_delivery,
            'cleanupMinutes' => (int) $settings->cleanup_minutes,
            'oneVehiclePerArea' => (bool) $settings->one_vehicle_per_area,
            'autoAssignArea' => (bool) $settings->auto_assign_area,
            'requireColor' => (bool) $settings->require_color,
            'requireBrandModel' => (bool) $settings->require_brand_model,
            'requireYear' => (bool) $settings->require_year,
            'validateEcPlate' => (bool) $settings->validate_ec_plate,
            'pricingMode' => $settings->pricing_mode,
            'dirtySurcharge' => $item($settings->dirtySurcharge),
            'peakSurcharge' => $item($settings->peakSurcharge),
            'peakFrom' => $time($settings->peak_from),
            'peakTo' => $time($settings->peak_to),
            'roundingStep' => $settings->rounding_step === null ? null : (string) $settings->rounding_step,
            'commissionType' => $settings->commission_type,
            'commissionValue' => (string) $settings->commission_value,
            'assignmentMode' => $settings->assignment_mode,
            'maxOrdersPerWasher' => $settings->max_orders_per_washer,
            'consumeSuppliesOn' => $settings->consume_supplies_on,
            'supplyAlert' => (bool) $settings->supply_alert,
            'notifyReady' => (bool) $settings->notify_ready,
            'notifyReminder' => (bool) $settings->notify_reminder,
            'reminderHours' => (int) $settings->reminder_hours,
            'loyaltyEvery' => $settings->loyalty_every,
            'setupCompletedAt' => $settings->setup_completed_at?->toIso8601String(),
        ];
    }

    private function surchargeItem(int $companyId, string $publicId, string $field): Item
    {
        return Item::query()->where('company_id', $companyId)->where('public_id', $publicId)
            ->where('type', ItemType::SERVICE->value)->where('is_active', true)->first()
            ?? throw ValidationException::withMessages([$field => 'El recargo debe ser un servicio activo de Inventario.']);
    }
}
