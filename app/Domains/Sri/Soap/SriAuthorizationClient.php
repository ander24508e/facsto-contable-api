<?php

namespace App\Domains\Sri\Soap;

use App\Domains\Sri\DTOs\SriAuthorizationResult;
use App\Domains\Sri\Enums\SriOperation;

/** AutorizacionComprobantesOffline.autorizacionComprobante, always with the same 49-digit key. */
final class SriAuthorizationClient
{
    public function __construct(
        private readonly SriEndpointResolver $endpoints,
        private readonly SriSoapTransport $transport,
        private readonly SriResponseParser $parser,
    ) {}

    public function authorize(string $accessKey, string $environment): SriAuthorizationResult
    {
        if (preg_match('/^\d{49}$/', $accessKey) !== 1) {
            throw new \InvalidArgumentException('The access key must have 49 digits.');
        }
        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ec="http://ec.gob.sri.ws.autorizacion">'
            .'<soapenv:Header/><soapenv:Body><ec:autorizacionComprobante>'
            .'<claveAccesoComprobante>'.$accessKey.'</claveAccesoComprobante>'
            .'</ec:autorizacionComprobante></soapenv:Body></soapenv:Envelope>';

        $response = $this->transport->post($this->endpoints->resolve($environment, SriOperation::AUTHORIZATION), $envelope);
        try {
            return $this->parser->authorization($response->body, $accessKey)->withTransport($response->requestSha256, $response->httpStatus);
        } catch (SriResponseException $exception) {
            throw $exception->withTransport($response->requestSha256, $response->httpStatus);
        }
    }
}
