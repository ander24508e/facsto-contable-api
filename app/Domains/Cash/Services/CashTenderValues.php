<?php

namespace App\Domains\Cash\Services;

/** Extra declared values kept apart from physical cash in the drawer balance. */
final class CashTenderValues
{
    public const TYPES = ['CHECK', 'TRANSFER', 'OTHER'];

    /** @return list<array{code: string, name: string}> */
    public static function catalog(): array
    {
        return [
            ['code' => 'CHECK', 'name' => 'Cheque'],
            ['code' => 'TRANSFER', 'name' => 'Transferencia'],
            ['code' => 'OTHER', 'name' => 'Otro valor'],
        ];
    }

    /** @param list<array{type: string, amount: string}>|null $values
     *  @return list<array{type: string, amount: string}>
     */
    public static function normalize(?array $values): array
    {
        return array_values(array_map(static fn (array $value): array => [
            'type' => $value['type'],
            'amount' => bcadd($value['amount'], '0', 2),
        ], $values ?? []));
    }

    /** @param list<array{type: string, amount: string}> $values */
    public static function total(array $values): string
    {
        return array_reduce($values, static fn (string $sum, array $value): string => bcadd($sum, $value['amount'], 2), '0.00');
    }
}
