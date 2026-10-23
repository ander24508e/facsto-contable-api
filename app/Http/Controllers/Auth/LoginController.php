<?php

namespace App\Http\Controllers\Auth;

use App\Domains\Identity\Actions\AuthenticateUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class LoginController extends Controller
{
    public function __invoke(LoginRequest $request, AuthenticateUserAction $authenticate): JsonResponse|UserResource
    {
        $key = mb_strtolower($request->string('email')->toString()).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => ['Demasiados intentos. Inténtalo de nuevo en un momento.'],
            ]);
        }

        $user = $authenticate->execute(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request->boolean('remember'),
        );

        if (! $user) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => ['Las credenciales no son válidas.']]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return new UserResource($user);
    }
}
