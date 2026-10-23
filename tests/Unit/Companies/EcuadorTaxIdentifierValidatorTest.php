<?php

namespace Tests\Unit\Companies;

use App\Domains\Companies\Fiscal\Services\EcuadorTaxIdentifierValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EcuadorTaxIdentifierValidatorTest extends TestCase
{
    #[Test]
    public function it_checks_natural_public_and_private_ruc_check_digits(): void
    {
        $validator = app(EcuadorTaxIdentifierValidator::class);
        $this->assertTrue($validator->isValidRuc('1790016919001'));
        $this->assertFalse($validator->isValidRuc('1790016918001'));
        $this->assertTrue($validator->isValidRuc('1760001550001'));
    }
}
