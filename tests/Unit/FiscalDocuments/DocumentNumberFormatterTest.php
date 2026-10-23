<?php

namespace Tests\Unit\FiscalDocuments;

use App\Domains\FiscalDocuments\Services\DocumentNumberFormatter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DocumentNumberFormatterTest extends TestCase
{
    #[Test]
    public function it_formats_the_full_document_number(): void
    {
        $this->assertSame('001-002-000000009', app(DocumentNumberFormatter::class)->format('001', '002', '000000009'));
    }

    #[Test]
    public function it_rejects_codes_with_invalid_lengths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(DocumentNumberFormatter::class)->format('1', '002', '000000009');
    }
}
