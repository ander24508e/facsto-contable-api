<?php

namespace App\Domains\Dashboard\Services;

use App\Domains\Companies\Fiscal\Enums\RimpeType;
use App\Domains\Companies\Models\Company;
use Carbon\CarbonImmutable;

/**
 * The next tax returns of the company by the ninth digit of its RUC: 1 → day 10, 2 → 12 … 9 → 26,
 * 0 → 28 of the month after the period. A due date on Saturday or Sunday moves to Monday; national
 * holidays are not known here, so the card says the date the SRI table gives.
 *
 * - IVA (104): monthly; RIMPE emprendedor declares it by semester (January–June in July, July–December
 *   in January); a negocio popular does not declare IVA.
 * - Retenciones en la fuente (103): monthly, only for a withholding agent.
 * - Impuesto a la renta: March for a natural person (third digit 0–5), April for a company.
 */
final class TaxCalendar
{
    private const DAYS = ['1' => 10, '2' => 12, '3' => 14, '4' => 16, '5' => 18, '6' => 20, '7' => 22, '8' => 24, '9' => 26, '0' => 28];

    /** @return array{ninthDigit: ?string, dueDay: ?int, items: list<array{obligation: string, form: string, period: string, dueDate: string, daysLeft: int}>} */
    public function upcoming(Company $company, ?CarbonImmutable $today = null): array
    {
        $ruc = (string) $company->ruc;
        if (! preg_match('/^\d{13}$/', $ruc)) {
            return ['ninthDigit' => null, 'dueDay' => null, 'items' => []];
        }
        $today = ($today ?? CarbonImmutable::now((string) config('sri.timezone')))->startOfDay();
        $digit = $ruc[8];
        $day = self::DAYS[$digit];
        $settings = $company->fiscalSettings;
        $rimpe = $settings?->rimpe_type;
        $items = [];

        if ($rimpe !== RimpeType::POPULAR_BUSINESS) {
            $items[] = $rimpe === RimpeType::ENTREPRENEUR
                ? $this->semesterIva($today, $day)
                : $this->monthly($today, $day, 'Declaración de IVA', '104');
        }
        if (filled($settings?->withholding_agent_number)) {
            $items[] = $this->monthly($today, $day, 'Retenciones en la fuente', '103');
        }
        $items[] = $this->annualIncome($today, $day, (int) $ruc[2] <= 5);

        usort($items, fn (array $a, array $b): int => strcmp($a['dueDate'], $b['dueDate']));

        return ['ninthDigit' => $digit, 'dueDay' => $day, 'items' => $items];
    }

    /** The return of last month, or of this month when last month's date already passed. */
    private function monthly(CarbonImmutable $today, int $day, string $obligation, string $form): array
    {
        $period = $today->startOfMonth()->subMonth();
        if ($this->due($period->addMonth(), $day)->lt($today)) {
            $period = $period->addMonth();
        }

        return $this->item($obligation, $form, ucfirst($period->locale('es')->translatedFormat('F Y')), $this->due($period->addMonth(), $day), $today);
    }

    private function semesterIva(CarbonImmutable $today, int $day): array
    {
        foreach ([[$today->year - 1, 7], [$today->year, 1], [$today->year, 7], [$today->year + 1, 1]] as [$year, $month]) {
            $due = $this->due(CarbonImmutable::create($year, $month, 1, 0, 0, 0, $today->timezone), $day);
            if ($due->gte($today)) {
                $period = $month === 7 ? "Enero–junio {$year}" : 'Julio–diciembre '.($year - 1);

                return $this->item('Declaración semestral de IVA', '104', $period, $due, $today);
            }
        }

        return [];
    }

    private function annualIncome(CarbonImmutable $today, int $day, bool $naturalPerson): array
    {
        $month = $naturalPerson ? 3 : 4;
        $due = $this->due(CarbonImmutable::create($today->year, $month, 1, 0, 0, 0, $today->timezone), $day);
        if ($due->lt($today)) {
            $due = $this->due(CarbonImmutable::create($today->year + 1, $month, 1, 0, 0, 0, $today->timezone), $day);
        }

        return $this->item('Impuesto a la renta', $naturalPerson ? '102' : '101', 'Año '.($due->year - 1), $due, $today);
    }

    private function due(CarbonImmutable $month, int $day): CarbonImmutable
    {
        $date = $month->setDay($day);

        return match ($date->dayOfWeekIso) {
            6 => $date->addDays(2),
            7 => $date->addDay(),
            default => $date,
        };
    }

    private function item(string $obligation, string $form, string $period, CarbonImmutable $due, CarbonImmutable $today): array
    {
        return ['obligation' => $obligation, 'form' => $form, 'period' => $period, 'dueDate' => $due->toDateString(), 'daysLeft' => (int) $today->diffInDays($due)];
    }
}
