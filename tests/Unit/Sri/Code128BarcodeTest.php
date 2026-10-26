<?php

namespace Tests\Unit\Sri;

use App\Domains\Ride\Services\Code128Barcode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Code128BarcodeTest extends TestCase
{
    #[Test]
    public function every_symbol_has_eleven_modules_and_the_stop_thirteen(): void
    {
        foreach (Code128Barcode::PATTERNS as $value => $pattern) {
            $this->assertSame($value === 106 ? 13 : 11, array_sum(str_split($pattern)), "Symbol {$value}");
        }
        $this->assertCount(107, array_unique(Code128Barcode::PATTERNS));
    }

    #[Test]
    public function an_access_key_uses_set_c_pairs_and_set_b_for_the_odd_digit(): void
    {
        $key = '2610202601179001691900110010010000000011234567811';
        $symbols = (new Code128Barcode)->symbols($key);

        $this->assertSame(105, $symbols[0]);
        $this->assertSame(26, $symbols[1]);
        $this->assertSame(100, $symbols[25]); // switch to set B before the 49th digit
        $this->assertSame(ord('1') - 32, $symbols[26]);
        $this->assertSame(106, end($symbols));

        $checksum = 105;
        foreach (array_slice($symbols, 1, -2) as $position => $value) {
            $checksum += $value * ($position + 1);
        }
        $this->assertSame($checksum % 103, $symbols[count($symbols) - 2]);
        $this->assertStringStartsWith('<svg', (new Code128Barcode)->svg($key));
    }
}
