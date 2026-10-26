<?php

namespace Tests\Unit\Companies;

use App\Domains\Companies\Fiscal\Services\EcuadorRucValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EcuadorRucValidatorTest extends TestCase
{
    #[Test]
    public function it_validates_personal_identity_digits_but_does_not_invent_entity_ruc_algorithms(): void
    {
        $validator = app(EcuadorRucValidator::class);

        $this->assertTrue($validator->isStructurallyValid('1790016919001'));
        // Private-company RUCs (third digit 9) are not rejected based on
        // an establishment-side checksum that SRI does not require.
        $this->assertTrue($validator->isStructurallyValid('1790016918001'));
        $this->assertTrue($validator->isStructurallyValid('1760001550001'));
        $this->assertTrue($validator->isStructurallyValid('1761234567001'));
        $this->assertTrue($validator->isStructurallyValid('1791234567001'));
        $this->assertFalse($validator->isStructurallyValid('1791234567000'));
        $this->assertFalse($validator->isStructurallyValid('0091234567001'));
    }
}
