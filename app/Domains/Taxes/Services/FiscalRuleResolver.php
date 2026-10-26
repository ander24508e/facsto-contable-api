<?php

namespace App\Domains\Taxes\Services;

use App\Domains\Taxes\Enums\FiscalRuleCode;
use App\Domains\Taxes\Models\FiscalRule;
use Carbon\CarbonInterface;
use RuntimeException;

/** Resolves the rule version in force on a fiscal date, so old documents keep the rule of their day. */
final class FiscalRuleResolver
{
    public function decimalAt(FiscalRuleCode $code, CarbonInterface $date): string
    {
        $rule = FiscalRule::query()
            ->where('code', $code->value)
            ->where('is_active', true)
            ->whereDate('valid_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date))
            ->orderByDesc('valid_from')
            ->first();

        if (! $rule || $rule->value_decimal === null) {
            // The catalog seeder must have run; a missing rule is a deployment error, never a default.
            throw new RuntimeException("Fiscal rule {$code->value} is not defined for {$date->toDateString()}.");
        }

        return bcadd((string) $rule->value_decimal, '0', 2);
    }
}
