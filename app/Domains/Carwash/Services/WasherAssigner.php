<?php

namespace App\Domains\Carwash\Services;

use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\CarwashSetting;
use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Companies\Models\Company;
use App\Models\User;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;

/**
 * Configuración › Trabajadores. «Máximo de órdenes a la vez»: a washer with that many orders received or in progress
 * takes no more. «Asignación rotativa»: a new order without a washer goes to the one with the fewest orders at hand
 * and, between equals, the one who was assigned longest ago.
 */
final class WasherAssigner
{
    private const AT_HAND = [WorkOrderStatus::ARRIVED, WorkOrderStatus::IN_PROGRESS];

    public function __construct(private readonly WorkerDirectory $workers) {}

    /** The washer chosen for an order (the rotation when none is chosen on a new order), checked against the maximum. */
    public function assign(Company $company, ?string $publicId, ?int $current = null, ?WorkOrder $order = null, bool $rotate = false): ?User
    {
        $settings = CarwashSetting::for($company);
        $washer = $this->workers->resolve($company, $publicId, $current);
        if ($washer === null) {
            return $rotate && $settings->assignment_mode === 'ROTATION' ? $this->next($company, $settings) : null;
        }
        if ($washer->id !== $current && $settings->max_orders_per_washer !== null && $this->atHand($company, $washer->id, $order) >= $settings->max_orders_per_washer) {
            throw new ApiException(ApiErrorCode::CARWASH_WASHER_BUSY, "{$washer->name} ya tiene {$settings->max_orders_per_washer} órdenes a la vez.", 409, [
                'errors' => ['washerPublicId' => ["{$washer->name} ya tiene {$settings->max_orders_per_washer} órdenes a la vez."]],
            ]);
        }

        return $washer;
    }

    private function next(Company $company, CarwashSetting $settings): ?User
    {
        $candidates = $this->workers->list($company)
            ->map(fn (User $user) => ['user' => $user, 'atHand' => $this->atHand($company, $user->id), 'last' => (string) WorkOrder::query()
                ->where('company_id', $company->id)->where('washer_id', $user->id)->max('created_at')])
            ->filter(fn (array $candidate) => $settings->max_orders_per_washer === null || $candidate['atHand'] < $settings->max_orders_per_washer)
            ->sortBy([['atHand', 'asc'], ['last', 'asc']]);

        return $candidates->first()['user'] ?? null;
    }

    private function atHand(Company $company, int $washerId, ?WorkOrder $except = null): int
    {
        return WorkOrder::query()->where('company_id', $company->id)->where('washer_id', $washerId)->whereIn('status', self::AT_HAND)
            ->when($except?->exists, fn ($query) => $query->whereKeyNot($except->id))->count();
    }
}
