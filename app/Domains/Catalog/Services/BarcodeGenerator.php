<?php

namespace App\Domains\Catalog\Services;

use App\Domains\Catalog\Models\ItemCatalogMetadata;
use App\Domains\Companies\Models\Company;

/**
 * Generates an internal EAN-13 for a product without a factory code: prefix 20 (the GS1 range reserved
 * for in-store use, so it never collides with a manufacturer's code), a 10-digit company sequence and the
 * check digit. Any scanner reads it and the user can still replace it with the code on the package.
 */
final class BarcodeGenerator
{
    private const PREFIX = '20';

    public function next(Company $company): string
    {
        $last = ItemCatalogMetadata::query()->where('company_id', $company->id)
            ->where('barcode', 'like', self::PREFIX.'%')
            ->pluck('barcode')
            ->filter(fn (string $code): bool => strlen($code) === 13 && ctype_digit($code))
            ->map(fn (string $code): int => (int) substr($code, 2, 10))
            ->max() ?? 0;

        $body = self::PREFIX.str_pad((string) ($last + 1), 10, '0', STR_PAD_LEFT);

        return $body.self::checkDigit($body);
    }

    /** EAN-13 check digit of the first 12 digits: weights 1 and 3 from the left. */
    public static function checkDigit(string $twelveDigits): string
    {
        $sum = 0;
        foreach (str_split($twelveDigits) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 1 : 3);
        }

        return (string) ((10 - $sum % 10) % 10);
    }
}
