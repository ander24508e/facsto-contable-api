<?php

namespace App\Http\Controllers\Auth;

use App\Domains\Identity\Actions\RegisterUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\JsonResponse;

final class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request, RegisterUserAction $register): JsonResponse
    {
        $user = $register->execute(
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        auth()->login($user);
        $request->session()->regenerate();
        $request->session()->save();

        return (new UserResource($user))->response()->setStatusCode(201);
    }
}
