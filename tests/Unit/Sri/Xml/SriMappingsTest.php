<?php

namespace Tests\Unit\Sri\Xml;

use App\Domains\Companies\Fiscal\Enums\SriEnvironment;
use App\Domains\Customers\Enums\IdentificationType;
use App\Domains\FiscalDocuments\Enums\DocumentType;
use App\Domains\Sri\Mappers\SriCurrencyMapper;
use App\Domains\Sri\Mappers\SriDocumentTypeMapper;
use App\Domains\Sri\Mappers\SriEmissionTypeMapper;
use App\Domains\Sri\Mappers\SriEnvironmentMapper;
use App\Domains\Sri\Mappers\SriIdentificationTypeMapper;
use LogicException;
use PHPUnit\Framework\TestCase;

final class SriMappingsTest extends TestCase
{
    public function test_maps_domain_values_to_sri_values(): void
    {
        self::assertSame('01', (new SriDocumentTypeMapper())->map(DocumentType::INVOICE));
        self::assertSame('1', (new SriEnvironmentMapper())->map(SriEnvironment::TEST));
        self::assertSame('2', (new SriEnvironmentMapper())->map('PRODUCTION'));
        self::assertSame('1', (new SriEmissionTypeMapper())->map('NORMAL'));
        self::assertSame('04', (new SriIdentificationTypeMapper())->map(IdentificationType::RUC));
        self::assertSame('07', (new SriIdentificationTypeMapper())->map('FINAL_CONSUMER'));
        self::assertSame('DOLAR', (new SriCurrencyMapper())->map('USD'));
    }

    public function test_rejects_unsupported_sri_values(): void
    {
        $this->expectException(LogicException::class);
        (new SriEmissionTypeMapper())->map('SPECIAL');
    }
}
