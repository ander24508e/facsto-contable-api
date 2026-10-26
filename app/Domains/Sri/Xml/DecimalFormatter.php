<?php

namespace App\Domains\Sri\Xml;

use InvalidArgumentException;

final class DecimalFormatter
{
    public function money(string|int $value): string
    {
        return bcadd($this->decimal($value), '0', 2);
    }

    public function quantity(string|int $value): string
    {
        return $this->trim(bcadd($this->decimal($value), '0', 6));
    }

    public function unitPrice(string|int $value): string
    {
        return $this->trim(bcadd($this->decimal($value), '0', 6));
    }

    public function percentage(string|int $value): string
    {
        return $this->trim(bcadd($this->decimal($value), '0', 2));
    }

    public function taxBase(string|int $value): string
    {
        return $this->money($value);
    }

    public function taxAmount(string|int $value): string
    {
        return $this->money($value);
    }

    private function decimal(string|int $value): string
    {
        $value = (string) $value;
        if (! preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            throw new InvalidArgumentException('A fiscal decimal must be a plain decimal string.');
        }

        return $value;
    }

    private function trim(string $value): string
    {
        $value = rtrim(rtrim($value, '0'), '.');

        return $value === '' || $value === '-0' ? '0' : $value;
    }
}
