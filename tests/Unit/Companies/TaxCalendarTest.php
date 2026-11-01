<?php

namespace Tests\Unit\Companies;

use App\Domains\Companies\Fiscal\Enums\RimpeType;
use App\Domains\Companies\Fiscal\Models\CompanyFiscalSettings;
use App\Domains\Companies\Models\Company;
use App\Domains\Dashboard\Services\TaxCalendar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TaxCalendarTest extends TestCase
{
    private function company(string $ruc, ?RimpeType $rimpe = null, ?string $agent = null): Company
    {
        $company = new Company;
        $company->ruc = $ruc;
        $company->setRelation('fiscalSettings', new CompanyFiscalSettings(['rimpe_type' => $rimpe, 'withholding_agent_number' => $agent]));

        return $company;
    }

    #[Test]
    public function a_company_declares_iva_monthly_and_income_in_april_by_its_ninth_digit(): void
    {
        $today = CarbonImmutable::create(2026, 11, 1, 9, 0, 0, 'America/Guayaquil');
        $calendar = app(TaxCalendar::class)->upcoming($this->company('1799999999001', agent: '1'), $today);

        $this->assertSame('9', $calendar['ninthDigit']);
        $this->assertSame(26, $calendar['dueDay']);
        $this->assertSame(['104', '103', '101'], array_column($calendar['items'], 'form'));
        $this->assertSame('2026-11-26', $calendar['items'][0]['dueDate']);
        $this->assertSame('Octubre 2026', $calendar['items'][0]['period']);
        $this->assertSame(25, $calendar['items'][0]['daysLeft']);
        $this->assertSame('2027-04-26', $calendar['items'][2]['dueDate']);
    }

    #[Test]
    public function a_rimpe_entrepreneur_declares_iva_by_semester_and_a_weekend_moves_to_monday(): void
    {
        $today = CarbonImmutable::create(2026, 11, 1, 9, 0, 0, 'America/Guayaquil');
        $calendar = app(TaxCalendar::class)->upcoming($this->company('1710034065001', RimpeType::ENTREPRENEUR), $today);

        $this->assertSame(20, $calendar['dueDay']);
        $this->assertSame([
            ['Declaración semestral de IVA', 'Julio–diciembre 2026', '2027-01-20'],
            ['Impuesto a la renta', 'Año 2026', '2027-03-22'],
        ], array_map(fn (array $item): array => [$item['obligation'], $item['period'], $item['dueDate']], $calendar['items']));
    }

    #[Test]
    public function a_popular_business_only_declares_income_and_a_company_without_ruc_has_no_calendar(): void
    {
        $today = CarbonImmutable::create(2026, 11, 1, 9, 0, 0, 'America/Guayaquil');

        $this->assertSame(['102'], array_column(app(TaxCalendar::class)->upcoming($this->company('1710034065001', RimpeType::POPULAR_BUSINESS), $today)['items'], 'form'));
        $this->assertSame([], app(TaxCalendar::class)->upcoming($this->company(''), $today)['items']);
    }
}
