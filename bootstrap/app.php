<?php

use App\Domains\Companies\Models\Company;
use App\Http\Middleware\ResolveCompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        then: function (): void {
            require __DIR__.'/../routes/auth.php';
        },
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->trimStrings(except: ['password', 'password_confirmation']);
        $middleware->alias([
            'company.context' => ResolveCompanyContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApiRequest = fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen(
            $isApiRequest,
        );

        $exceptions->render(function (ValidationException $exception, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request)) {
                return null;
            }

            return response()->json(['error' => [
                'code' => 'VALIDATION_FAILED',
                'message' => 'Los datos enviados no son válidos.',
                'details' => ['errors' => $exception->errors()],
            ]], 422);
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request)) {
                return null;
            }

            return response()->json(['error' => [
                'code' => 'AUTHENTICATION_REQUIRED',
                'message' => 'Inicia sesión para continuar.',
                'details' => (object) [],
            ]], 401);
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request)) {
                return null;
            }

            return response()->json(['error' => [
                'code' => 'COMPANY_ACCESS_DENIED',
                'message' => 'No tienes permiso para realizar esta operación.',
                'details' => (object) [],
            ]], 403);
        });

        $exceptions->render(function (TokenMismatchException $exception, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request)) {
                return null;
            }

            return response()->json(['error' => [
                'code' => 'CSRF_TOKEN_MISMATCH',
                'message' => 'La sesión expiró. Actualiza la protección CSRF e inténtalo de nuevo.',
                'details' => (object) [],
            ]], 419);
        });

        $exceptions->render(function (ModelNotFoundException $exception, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request)) {
                return null;
            }

            $isCompany = $exception->getModel() === Company::class;

            return response()->json(['error' => [
                'code' => $isCompany ? 'COMPANY_NOT_FOUND' : 'RESOURCE_NOT_FOUND',
                'message' => $isCompany ? 'La empresa solicitada no existe.' : 'El recurso solicitado no existe.',
                'details' => (object) [],
            ]], 404);
        });
    })->create();
