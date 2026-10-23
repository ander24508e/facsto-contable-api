<?php

namespace Tests\Unit\FiscalDocuments;

use App\Domains\FiscalDocuments\Services\Modulo11Service;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class Modulo11ServiceTest extends TestCase
{
    #[Test]
    public function it_returns_a_single_check_digit_for_a_numeric_base(): void
    {
        $this->assertSame(2, app(Modulo11Service::class)->checkDigit('123456789'));
    }
}
