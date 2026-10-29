<?php

namespace Tests\Unit\Sri\Xml;

use App\Domains\FiscalDocuments\Enums\DocumentType;
use App\Domains\Sri\DTOs\InvoiceLineXmlData;
use App\Domains\Sri\DTOs\InvoicePaymentXmlData;
use App\Domains\Sri\DTOs\InvoiceTaxXmlData;
use App\Domains\Sri\DTOs\InvoiceXmlData;
use App\Domains\Sri\Xml\Builders\AdditionalInformationBuilder;
use App\Domains\Sri\Xml\Builders\InfoFacturaBuilder;
use App\Domains\Sri\Xml\Builders\InfoNotaCreditoBuilder;
use App\Domains\Sri\Xml\Builders\InfoTributariaBuilder;
use App\Domains\Sri\Xml\Builders\InvoiceDetailsBuilder;
use App\Domains\Sri\Xml\Builders\InvoicePaymentsBuilder;
use App\Domains\Sri\Xml\DecimalFormatter;
use App\Domains\Sri\Xml\InvoiceXmlBuilder;
use App\Domains\Sri\Xml\Requirements\ProviderRucRequirement;
use App\Domains\Sri\Xml\Requirements\RimpeRequirement;
use App\Domains\Sri\Xml\Requirements\WithholdingAgentRequirement;
use App\Domains\Sri\Xml\XmlSchemaValidationException;
use App\Domains\Sri\Xml\XmlTextNormalizer;
use App\Domains\Sri\Xml\XsdValidator;
use PHPUnit\Framework\TestCase;

final class InvoiceXmlBuilderTest extends TestCase
{
    public function test_builds_escaped_dom_xml_and_validates_against_official_xsd(): void
    {
        $xml = $this->builder()->build($this->data());
        $dom = new \DOMDocument;
        self::assertTrue($dom->loadXML($xml));
        $xpath = new \DOMXPath($dom);
        self::assertSame('CONTRIBUYENTE & Cía. <Endara>', $xpath->query('/factura/infoTributaria/razonSocial')->item(0)?->textContent);
        self::assertSame('25/10/2026', $xpath->query('/factura/infoFactura/fechaEmision')->item(0)?->textContent);
        self::assertSame('1.234567', $xpath->query('/factura/detalles/detalle/cantidad')->item(0)?->textContent);
        self::assertSame('10.123456', $xpath->query('/factura/detalles/detalle/precioUnitario')->item(0)?->textContent);
        self::assertSame('RUC Proveedor', $xpath->query('/factura/infoAdicional/campoAdicional/@nombre')->item(0)?->nodeValue);

        (new XsdValidator)->validate($xml, dirname(__DIR__, 4).'/resources/sri/xsd/invoice/1.1.0/factura.xsd');
    }

    public function test_rimpe_popular_label_is_emitted_as_a_snapshot_value(): void
    {
        $xml = $this->builder()->build($this->data(rimpeType: 'POPULAR_BUSINESS'));
        $dom = new \DOMDocument;
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        self::assertSame('CONTRIBUYENTE NEGOCIO POPULAR - RÉGIMEN RIMPE', $xpath->query('/factura/infoTributaria/contribuyenteRimpe')->item(0)?->textContent);
    }

    public function test_official_1_1_schema_rejects_the_popular_rimpe_value_required_by_ficha_2_34(): void
    {
        $xml = $this->builder()->build($this->data(rimpeType: 'POPULAR_BUSINESS'));

        try {
            (new XsdValidator)->validate($xml, dirname(__DIR__, 4).'/resources/sri/xsd/invoice/1.1.0/factura.xsd');
            self::fail('The official 1.1.0 schema unexpectedly accepted the Ficha 2.34 popular-business label.');
        } catch (XmlSchemaValidationException $exception) {
            self::assertStringContainsString('contribuyenteRimpe', implode(' ', array_column($exception->errors, 'message')));
        }
    }

    private function builder(): InvoiceXmlBuilder
    {
        $text = new XmlTextNormalizer;
        $decimals = new DecimalFormatter;
        $payments = new InvoicePaymentsBuilder($text, $decimals);

        return new InvoiceXmlBuilder(
            new InfoTributariaBuilder($text, new WithholdingAgentRequirement($text), new RimpeRequirement($text)),
            new InfoFacturaBuilder($text, $decimals, $payments),
            new InfoNotaCreditoBuilder($text, $decimals),
            new InvoiceDetailsBuilder($text, $decimals),
            new AdditionalInformationBuilder($text, new ProviderRucRequirement($text)),
        );
    }

    private function data(?string $rimpeType = null): InvoiceXmlData
    {
        $tax = new InvoiceTaxXmlData('2', '0', '0', '12.50', '0.00');

        return new InvoiceXmlData(
            '1.1.0', '1', '1', 'CONTRIBUYENTE & Cía. <Endara>', null, '1790016919001', str_repeat('1', 49),
            '01', '001', '001', '000000001', 'Quito', '25/10/2026', 'Quito', null, false,
            '05', 'José & María', '0102030405', 'Quito', '12.50', '0.00', [$tax], '0.00', '12.50', 'DOLAR',
            [new InvoicePaymentXmlData('01', '12.50', null, null)],
            [new InvoiceLineXmlData('SERV-1', null, 'Café <especial> & ñ', '1.234567', '10.123456', '0.00', '12.50', [$tax])],
            null, null, $rimpeType, '1790012345001', DocumentType::INVOICE,
        );
    }
}
