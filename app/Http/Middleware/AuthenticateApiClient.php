<?php

namespace App\Http\Middleware;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Enums\CompanyStatus;
use App\Domains\Integrations\Models\ApiClient;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module requests (Taller, Lavadora…): "Authorization: Bearer fct_…". Only the SHA-256 of the token is
 * stored; the token decides the company, so a module can never act on another company.
 */
final class AuthenticateApiClient
{
    public function __construct(private readonly CompanyContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();
        $client = str_starts_with($token, ApiClient::TOKEN_PREFIX)
            ? ApiClient::query()->where('token_hash', hash('sha256', $token))->with(['company', 'creator'])->first()
            : null;

        if (! $client || ! $client->is_active) {
            throw new ApiException(ApiErrorCode::API_CLIENT_INVALID, 'El token del módulo no es válido o fue revocado.', 401);
        }
        if ($client->company->status !== CompanyStatus::ACTIVE) {
            throw new ApiException(ApiErrorCode::COMPANY_INACTIVE, 'La empresa está inactiva.', 403);
        }

        $this->context->set($client->company);
        $request->attributes->set('apiClient', $client);
        $request->setUserResolver(fn () => $client->creator);
        if ($client->last_used_at === null || $client->last_used_at->lt(now()->subMinute())) {
            $client->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
