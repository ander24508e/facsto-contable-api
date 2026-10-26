<?php

namespace App\Domains\Sri\Soap;

use App\Domains\Sri\DTOs\SriReceptionResult;
use App\Domains\Sri\Enums\SriOperation;

/** RecepcionComprobantesOffline.validarComprobante with the signed XML in base64. */
final class SriReceptionClient
{
    public function __construct(
        private readonly SriEndpointResolver $endpoints,
        private readonly SriSoapTransport $transport,
        private readonly SriResponseParser $parser,
    ) {}

    public function validate(string $signedXml, string $environment): SriReceptionResult
    {
        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ec="http://ec.gob.sri.ws.recepcion">'
            .'<soapenv:Header/><soapenv:Body><ec:validarComprobante>'
            .'<xml>'.base64_encode($signedXml).'</xml>'
            .'</ec:validarComprobante></soapenv:Body></soapenv:Envelope>';

        $response = $this->transport->post($this->endpoints->resolve($environment, SriOperation::RECEPTION), $envelope);
        try {
            return $this->parser->reception($response->body)->withTransport($response->requestSha256, $response->httpStatus);
        } catch (SriResponseException $exception) {
            throw $exception->withTransport($response->requestSha256, $response->httpStatus);
        }
    }
}
