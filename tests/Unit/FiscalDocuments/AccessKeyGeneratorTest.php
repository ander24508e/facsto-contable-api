<?php

namespace Tests\Unit\FiscalDocuments;

use App\Domains\Companies\Fiscal\Enums\EmissionType;
use App\Domains\Companies\Fiscal\Enums\SriEnvironment;
use App\Domains\FiscalDocuments\Enums\DocumentType;
use App\Domains\FiscalDocuments\Services\AccessKeyGenerator;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AccessKeyGeneratorTest extends TestCase
{
    #[Test]
    public function it_builds_a_49_digit_invoice_access_key(): void
    {
        $key = app(AccessKeyGenerator::class)->generate(new DateTimeImmutable('2026-10-23'), DocumentType::INVOICE, '1790016919001', SriEnvironment::TEST, '001', '001', '000000001', '12345678', EmissionType::NORMAL);
        $this->assertSame(49, strlen($key));
        $this->assertMatchesRegularExpression('/^\d{49}$/', $key);
        $this->assertSame('23102026', substr($key, 0, 8));
    }

    #[Test]
    public function it_rejects_invalid_identity_components(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(AccessKeyGenerator::class)->generate(new DateTimeImmutable, DocumentType::INVOICE, '123', SriEnvironment::TEST, '001', '001', '000000001', '12345678', EmissionType::NORMAL);
    }

    #[Test]
    public function it_rejects_every_component_with_an_invalid_width(): void
    {
        $generator = app(AccessKeyGenerator::class);
        $valid = [new DateTimeImmutable, DocumentType::INVOICE, '1790016919001', SriEnvironment::TEST, '001', '001', '000000001', '12345678', EmissionType::NORMAL];
        foreach ([4 => '01', 5 => '1', 6 => '00000001', 7 => '1234567'] as $position => $invalidValue) {
            $input = $valid;
            $input[$position] = $invalidValue;
            try {
                $generator->generate(...$input);
                $this->fail('El generador aceptó una parte con longitud inválida.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }
}
