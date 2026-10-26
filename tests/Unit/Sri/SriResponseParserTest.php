<?php

namespace Tests\Unit\Sri;

use App\Domains\Sri\Enums\SriAuthorizationStatus;
use App\Domains\Sri\Enums\SriReceptionStatus;
use App\Domains\Sri\Soap\SriResponseException;
use App\Domains\Sri\Soap\SriResponseParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesSriWebServices;
use Tests\TestCase;

final class SriResponseParserTest extends TestCase
{
    use FakesSriWebServices;

    private const KEY = '2610202601179001691900110010010000000011234567811';

    #[Test]
    public function reception_answers_and_their_messages_are_read(): void
    {
        $parser = new SriResponseParser;
        $this->assertSame(SriReceptionStatus::RECEIVED, $parser->reception($this->receptionResponse('RECIBIDA'))->status);

        $returned = $parser->reception($this->receptionResponse('DEVUELTA', self::KEY, [['identifier' => '70', 'message' => 'CLAVE DE ACCESO EN PROCESAMIENTO', 'info' => 'x']]));
        $this->assertSame(SriReceptionStatus::RETURNED, $returned->status);
        $this->assertSame('70', $returned->messages[0]->identifier);
        $this->assertSame('ERROR', $returned->messages[0]->type);
        $this->assertTrue($returned->alreadyReceived());

        $structural = $parser->reception($this->receptionResponse('DEVUELTA', self::KEY, [['identifier' => '35', 'message' => 'ESTRUCTURA'], ['identifier' => '43', 'message' => 'REGISTRADA']]));
        $this->assertFalse($structural->alreadyReceived());
    }

    #[Test]
    public function authorization_data_comes_from_the_sri_answer(): void
    {
        $parser = new SriResponseParser;
        $comprobante = '<?xml version="1.0" encoding="UTF-8"?><factura id="comprobante"><infoTributaria><claveAcceso>'.self::KEY.'</claveAcceso></infoTributaria></factura>';

        $result = $parser->authorization($this->authorizationResponse(self::KEY, 'AUTORIZADO', $comprobante), self::KEY);
        $this->assertSame(SriAuthorizationStatus::AUTHORIZED, $result->status);
        $this->assertSame(self::KEY, $result->authorizationNumber);
        $this->assertSame('2026-10-26T15:15:30+00:00', $result->authorizedAt?->utc()->toIso8601String());
        $this->assertSame('PRUEBAS', $result->environment);
        $this->assertSame($comprobante, $result->comprobante);
        $this->assertStringContainsString('<estado>AUTORIZADO</estado>', (string) $result->authorizationXml);

        $this->assertSame(SriAuthorizationStatus::PENDING, $parser->authorization($this->authorizationResponse(self::KEY, null), self::KEY)->status);
        $this->assertSame(SriAuthorizationStatus::PENDING, $parser->authorization($this->authorizationResponse(self::KEY, 'EN PROCESO'), self::KEY)->status);
        $this->assertSame(SriAuthorizationStatus::NOT_AUTHORIZED, $parser->authorization($this->authorizationResponse(self::KEY, 'NO AUTORIZADO'), self::KEY)->status);
    }

    #[Test]
    public function unreadable_or_foreign_answers_are_rejected(): void
    {
        $parser = new SriResponseParser;
        foreach ([
            'not xml' => fn () => $parser->reception('<html>'),
            'soap fault' => fn () => $parser->reception('<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault><faultstring>x</faultstring></soap:Fault></soap:Body></soap:Envelope>'),
            'doctype' => fn () => $parser->reception('<!DOCTYPE x [<!ENTITY e "y">]><x>&e;</x>'),
            'other key' => fn () => $parser->authorization($this->authorizationResponse(str_repeat('1', 49), null), self::KEY),
            'no date' => fn () => $parser->authorization(str_replace('<fechaAutorizacion>2026-10-26T10:15:30-05:00</fechaAutorizacion>', '', $this->authorizationResponse(self::KEY, 'AUTORIZADO', '<factura/>')), self::KEY),
        ] as $case => $parse) {
            try {
                $parse();
                $this->fail("Accepted: {$case}");
            } catch (SriResponseException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
