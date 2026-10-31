<?php

namespace App\Domains\Cash\Services;

/** The dollar bills and coins in circulation in Ecuador, and the total of a count of them (in exact decimals). */
final class CashDenominations
{
    public const BILLS = ['100', '50', '20', '10', '5', '1'];

    public const COINS = ['1', '0.50', '0.25', '0.10', '0.05', '0.01'];

    /**
     * Keeps only the known denominations with a quantity above zero.
     *
     * @param  array{bills?: array<string, int>, coins?: array<string, int>}  $count
     * @return array{bills: array<string, int>, coins: array<string, int>}
     */
    public static function normalize(array $count): array
    {
        $pick = fn (array $allowed, array $given): array => array_filter(
            array_combine($allowed, array_map(fn (string $value): int => (int) ($given[$value] ?? 0), $allowed)),
            fn (int $quantity): bool => $quantity > 0,
        );

        return ['bills' => $pick(self::BILLS, $count['bills'] ?? []), 'coins' => $pick(self::COINS, $count['coins'] ?? [])];
    }

    /**
     * The money of an opening or a closing: the fixed amount (MANUAL) or the sum of the count (COUNTED),
     * which is always recomputed here.
     *
     * @return array{0: string, 1: array{bills: array<string, int>, coins: array<string, int>}|null}
     */
    public static function resolve(string $mode, ?string $amount, ?array $count): array
    {
        if ($mode === 'COUNTED') {
            $count = self::normalize($count ?? []);

            return [self::total($count), $count];
        }

        return [bcadd($amount ?? '0', '0', 2), null];
    }

    /** @param  array{bills: array<string, int>, coins: array<string, int>}  $count */
    public static function total(array $count): string
    {
        $total = '0.00';
        foreach (['bills', 'coins'] as $kind) {
            foreach ($count[$kind] as $value => $quantity) {
                $total = bcadd($total, bcmul((string) $value, (string) $quantity, 2), 2);
            }
        }

        return $total;
    }
}
