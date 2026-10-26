<?php

namespace Tests\Support;

use App\Domains\Companies\Models\Company;

/** SOAP answers shaped like the SRI offline web services (ficha técnica v2.34). */
trait FakesSriWebServices
{
    protected const RECEPTION_TEST_URL = 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline';

    protected const AUTHORIZATION_TEST_URL = 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline';

    /** Opens the three keys of the transmission gate. */
    protected function enableSriTransmission(Company $company): void
    {
        config(['sri.transport_enabled' => true, 'sri.software_provider.registered' => true]);
        $company->fiscalSettings()->update(['sri_submission_enabled' => true]);
    }

    /** @param list<array{identifier: string, message: string, type?: string, info?: string}> $messages */
    protected function receptionResponse(string $estado, string $accessKey = '', array $messages = []): string
    {
        $comprobantes = $messages === [] ? '<comprobantes/>' : '<comprobantes><comprobante><claveAcceso>'.$accessKey.'</claveAcceso><mensajes>'
            .$this->messagesXml($messages).'</mensajes></comprobante></comprobantes>';

        return '<?xml version="1.0" encoding="UTF-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            .'<ns2:validarComprobanteResponse xmlns:ns2="http://ec.gob.sri.ws.recepcion"><RespuestaRecepcionComprobante>'
            .'<estado>'.$estado.'</estado>'.$comprobantes
            .'</RespuestaRecepcionComprobante></ns2:validarComprobanteResponse></soap:Body></soap:Envelope>';
    }

    /** @param list<array{identifier: string, message: string, type?: string, info?: string}> $messages */
    protected function authorizationResponse(string $accessKey, ?string $estado, string $comprobante = '', array $messages = [], string $fecha = '2026-10-26T10:15:30-05:00'): string
    {
        $authorizations = $estado === null ? '<autorizaciones/>' : '<autorizaciones><autorizacion>'
            .'<estado>'.$estado.'</estado>'
            .($estado === 'AUTORIZADO' ? '<numeroAutorizacion>'.$accessKey.'</numeroAutorizacion><fechaAutorizacion>'.$fecha.'</fechaAutorizacion>' : '')
            .'<ambiente>PRUEBAS</ambiente>'
            .'<comprobante><![CDATA['.$comprobante.']]></comprobante>'
            .'<mensajes>'.$this->messagesXml($messages).'</mensajes>'
            .'</autorizacion></autorizaciones>';

        return '<?xml version="1.0" encoding="UTF-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            .'<ns2:autorizacionComprobanteResponse xmlns:ns2="http://ec.gob.sri.ws.autorizacion"><RespuestaAutorizacionComprobante>'
            .'<claveAccesoConsultada>'.$accessKey.'</claveAccesoConsultada><numeroComprobantes>'.($estado === null ? '0' : '1').'</numeroComprobantes>'
            .$authorizations
            .'</RespuestaAutorizacionComprobante></ns2:autorizacionComprobanteResponse></soap:Body></soap:Envelope>';
    }

    /** @param list<array{identifier: string, message: string, type?: string, info?: string}> $messages */
    private function messagesXml(array $messages): string
    {
        return implode('', array_map(fn (array $message): string => '<mensaje><identificador>'.$message['identifier'].'</identificador>'
            .'<mensaje>'.$message['message'].'</mensaje>'
            .(isset($message['info']) ? '<informacionAdicional>'.$message['info'].'</informacionAdicional>' : '')
            .'<tipo>'.($message['type'] ?? 'ERROR').'</tipo></mensaje>', $messages));
    }
}
