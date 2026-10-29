<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Companies\Models\Company;
use Illuminate\Support\Str;

/**
 * Suggests the main code of a new item: 3 letters of the category, 3 of the name and the next free
 * number, e.g. BEB-AGU-001. The user can still change it; uniqueness is checked when saving.
 */
final class ItemCodeSuggester
{
    public function suggest(Company $company, string $name, ?string $category): string
    {
        $prefix = implode('-', array_filter([$this->letters($category ?? ''), $this->letters($name)])) ?: 'ART';
        $taken = $company->items()->where('internal_code', 'like', $prefix.'-%')->pluck('internal_code');
        $next = 1;
        foreach ($taken as $code) {
            $suffix = substr($code, strlen($prefix) + 1);
            if (ctype_digit($suffix)) {
                $next = max($next, (int) $suffix + 1);
            }
        }

        return $prefix.'-'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    /** The first 3 letters or digits, without accents, in capitals. */
    private function letters(string $value): string
    {
        return substr((string) preg_replace('/[^A-Z0-9]/', '', Str::upper(Str::ascii($value))), 0, 3);
    }
}
