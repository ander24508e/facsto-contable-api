<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\Request;

final class CurrentUserController extends Controller
{
    public function __invoke(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
