<?php

namespace Tests\Unit\FiscalDocuments;

use App\Domains\FiscalDocuments\Services\SequentialFormatter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SequentialFormatterTest extends TestCase
{
    #[Test]
    public function it_pads_sequential_to_nine_digits(): void
    {
        $this->assertSame('000000001', app(SequentialFormatter::class)->format(1));
    }

    #[Test]
    public function it_rejects_values_outside_the_supported_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(SequentialFormatter::class)->format(1000000000);
    }
}
