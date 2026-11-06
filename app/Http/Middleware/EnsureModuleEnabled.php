<?php

namespace App\Http\Middleware;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Modules\Enums\EntitlementStatus;
use App\Domains\Modules\Services\ModuleRegistry;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "module:carwash" — after auth and company.context: the company must have the module. READ_ONLY (plan
 * expired or unpaid) still reads but never writes; the data is kept.
 */
final class EnsureModuleEnabled
{
    public function __construct(private readonly CompanyContext $context, private readonly ModuleRegistry $modules) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $status = $this->modules->status($this->context->company(), $feature);
        if ($status === EntitlementStatus::DISABLED) {
            throw new ApiException(ApiErrorCode::MODULE_NOT_ENABLED, 'La empresa no tiene contratado este módulo.', 403, ['feature' => $feature]);
        }
        if ($status === EntitlementStatus::READ_ONLY && ! $request->isMethodSafe()) {
            throw new ApiException(ApiErrorCode::MODULE_READ_ONLY, 'El módulo está en solo lectura. Regulariza el plan para seguir registrando.', 403, ['feature' => $feature]);
        }

        return $next($request);
    }
}
