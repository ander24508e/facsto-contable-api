<?php

namespace Tests\Unit\Sri\Xml;

use App\Domains\Sri\Xml\DecimalFormatter;
use PHPUnit\Framework\TestCase;

final class DecimalFormatterTest extends TestCase
{
    public function test_serializes_money_and_six_decimal_quantities_without_float_conversion(): void
    {
        $formatter = new DecimalFormatter();
        self::assertSame('10.20', $formatter->money('10.2'));
        self::assertSame('2.123456', $formatter->quantity('2.123456'));
        self::assertSame('0.000001', $formatter->unitPrice('0.000001'));
        self::assertSame('15', $formatter->percentage('15.00'));
    }
}
