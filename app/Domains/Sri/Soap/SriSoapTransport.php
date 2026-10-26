<?php

namespace App\Domains\Sri\Soap;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * HTTP/SOAP only: POST over TLS, timeouts, headers and the raw answer. It never interprets
 * fiscal states. SOAP faults (HTTP 500) and other server errors are technical failures.
 */
final class SriSoapTransport
{
    public function post(string $url, string $envelope): SriTransportResponse
    {
        $requestSha256 = hash('sha256', $envelope);
        try {
            $response = Http::withHeaders(['SOAPAction' => '""'])
                ->withBody($envelope, 'text/xml; charset=utf-8')
                ->accept('text/xml')
                ->connectTimeout((int) config('sri.http.connect_timeout', 10))
                ->timeout((int) config('sri.http.timeout', 30))
                ->withOptions(['verify' => true])
                ->post($url);
        } catch (ConnectionException) {
            throw new SriTransportException('SRI_CONNECTION_FAILED', null, $requestSha256);
        }

        if (! $response->successful()) {
            throw new SriTransportException('SRI_HTTP_'.$response->status(), $response->status(), $requestSha256);
        }

        return new SriTransportResponse($response->status(), $response->body(), $requestSha256);
    }
}
