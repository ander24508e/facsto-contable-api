<?php

namespace App\Http\Middleware;

use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use App\Shared\Integration\CondoreSignature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-to-server calls from Condore Platform: X-Condore-Client "platform" and a valid CondoreSignature
 * made with CONDORE_PLATFORM_SECRET within 5 minutes. The host is not signed, so any subdomain works.
 */
final class VerifyPlatformSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('modules.platform.secret');
        $valid = $request->header(CondoreSignature::HEADER_CLIENT) === config('modules.platform.client_id')
            && CondoreSignature::verify(
                $secret,
                (string) $request->header(CondoreSignature::HEADER_TIMESTAMP),
                $request->method(),
                $request->getRequestUri(),
                $request->header(CondoreSignature::HEADER_USER),
                $request->getContent(),
                (string) $request->header(CondoreSignature::HEADER_SIGNATURE),
            );
        if (! $valid) {
            throw new ApiException(ApiErrorCode::INTERNAL_SIGNATURE_INVALID, 'Firma de Condore Platform inválida o vencida.', 401);
        }

        return $next($request);
    }
}
