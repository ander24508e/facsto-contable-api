<?php

namespace Tests\Unit\Taxes;

use App\Domains\Taxes\Enums\FiscalRuleCode;
use App\Domains\Taxes\Models\FiscalRule;
use App\Domains\Taxes\Services\FiscalRuleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

final class FiscalRuleResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_fiscal_date_resolves_the_rule_version_in_force_that_day(): void
    {
        $this->rule('50.00', '2023-02-01', '2027-12-31');
        $this->rule('100.00', '2028-01-01', null);
        $resolver = app(FiscalRuleResolver::class);

        $this->assertSame('50.00', $resolver->decimalAt(FiscalRuleCode::FINAL_CONSUMER_MAX_TOTAL, Carbon::parse('2026-10-25')));
        $this->assertSame('100.00', $resolver->decimalAt(FiscalRuleCode::FINAL_CONSUMER_MAX_TOTAL, Carbon::parse('2028-03-01')));
    }

    public function test_a_missing_rule_is_a_deployment_error_never_a_silent_default(): void
    {
        $this->expectException(RuntimeException::class);

        app(FiscalRuleResolver::class)->decimalAt(FiscalRuleCode::MAX_TIP_PERCENTAGE, Carbon::parse('2026-10-25'));
    }

    private function rule(string $value, string $from, ?string $until): void
    {
        FiscalRule::query()->create([
            'code' => FiscalRuleCode::FINAL_CONSUMER_MAX_TOTAL, 'value_type' => 'DECIMAL', 'value_decimal' => $value,
            'valid_from' => $from, 'valid_until' => $until, 'legal_basis' => 'Prueba', 'is_active' => true,
        ]);
    }
}
