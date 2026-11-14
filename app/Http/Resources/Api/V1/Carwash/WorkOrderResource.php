<?php

namespace App\Http\Resources\Api\V1\Carwash;

use App\Domains\Carwash\Enums\BillingStatus;
use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\CarwashSetting;
use App\Domains\Carwash\Services\ItemPrice;
use App\Domains\Carwash\Services\WorkflowSteps;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A work order with its billing read from the FACSTO sale: draft, invoice number, SRI state. Line prices
 * are the catalog's while unbilled, and the sale's own total once it exists.
 */
final class WorkOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $billing = BillingStatus::of($this->sale);
        $sale = $billing === BillingStatus::UNBILLED ? null : $this->sale;
        $document = $sale?->electronicDocument;
        $prices = app(ItemPrice::class);
        $settings = CarwashSetting::forCompany($this->company_id);

        return [
            'publicId' => $this->public_id,
            'code' => $this->code(),
            'number' => $this->number,
            'source' => $this->source->value,
            'status' => $this->status->value,
            // The next steps with Configuración › Servicios (without «Llegó» or «Listo» they are skipped).
            'allowedTransitions' => array_map(fn (WorkOrderStatus $status) => $status->value, app(WorkflowSteps::class)->next($this->status, $settings)),
            'receptionStatus' => app(WorkflowSteps::class)->reception($settings)->value,
            'checklist' => $this->checklist,
            'dirty' => (bool) $this->dirty,
            'loyaltyFree' => (bool) $this->loyalty_free,
            'isEditable' => $this->status->isEditable() && $billing === BillingStatus::UNBILLED,
            'vehicle' => $this->vehicle ? [
                'publicId' => $this->vehicle->public_id,
                'plate' => $this->vehicle->plate,
                'type' => $this->vehicle->type?->name,
                'color' => $this->vehicle->color,
            ] : null,
            'customer' => [
                'publicId' => $this->customer?->public_id,
                'name' => $this->customer?->legal_name,
                'identification' => $this->customer?->identification,
                'phone' => $this->customer?->phone,
            ],
            'createdBy' => $this->whenLoaded('creator', fn () => $this->creator ? ['name' => $this->creator->name] : null),
            'washer' => $this->washer ? ['publicId' => $this->washer->public_id, 'name' => $this->washer->name] : null,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'description' => $line->description,
                'kind' => $line->kind,
                'servicePublicId' => $line->service?->public_id,
                'itemCode' => $line->item?->internal_code,
                'quantity' => (string) $line->quantity,
                'price' => $line->item ? $prices->final($line->item) : null,
                'durationMinutes' => $line->duration_minutes,
            ])->values()),
            'estimatedMinutes' => $this->whenLoaded('lines', fn () => (int) $this->lines->sum('duration_minutes')),
            // The total of its sale once charged; before that, its services at the Inventario price (bcmath).
            'total' => $sale ? (string) $sale->total : $this->whenLoaded('lines', fn () => $this->lines->reduce(
                fn (string $sum, $line): string => bcadd($sum, bcmul($line->item ? $prices->final($line->item) : '0', (string) $line->quantity, 2), 2),
                '0.00',
            ), null),
            'notes' => $this->notes,
            'cancelReason' => $this->cancel_reason,
            'scheduledAt' => $this->scheduled_at?->toIso8601String(),
            'scheduledEndAt' => $this->scheduled_end_at?->toIso8601String(),
            'workArea' => $this->workArea ? ['publicId' => $this->workArea->public_id, 'name' => $this->workArea->name] : null,
            'timeline' => [
                'createdAt' => $this->created_at?->toIso8601String(),
                'arrivedAt' => $this->arrived_at?->toIso8601String(),
                'startedAt' => $this->started_at?->toIso8601String(),
                'readyAt' => $this->ready_at?->toIso8601String(),
                'deliveredAt' => $this->delivered_at?->toIso8601String(),
                'cancelledAt' => $this->cancelled_at?->toIso8601String(),
            ],
            'billing' => [
                'status' => $billing->value,
                'salePublicId' => $sale?->public_id,
                'saleNumber' => $sale?->displayNumber(),
                'total' => $sale ? (string) $sale->total : null,
                // Charged in the checkout window without invoice: its payment is already in the drawer («por facturar»).
                'paid' => $billing === BillingStatus::DRAFT && $sale->paymentMethods->isNotEmpty() && bccomp((string) $sale->paymentMethods->sum('amount'), (string) $sale->total, 2) === 0,
                'invoicePublicId' => $document?->public_id,
                'invoiceNumber' => $document?->full_number,
                'documentStatus' => $document?->status->value,
                'authorizedAt' => $document?->authorized_at?->toIso8601String(),
            ],
        ];
    }
}
