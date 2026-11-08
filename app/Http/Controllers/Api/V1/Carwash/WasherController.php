<?php

namespace App\Http\Controllers\Api\V1\Carwash;

use App\Domains\Carwash\Services\WorkerDirectory;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/** Trabajadores que se pueden asignar a una orden: usuarios con «Puede ser asignado como trabajador». */
final class WasherController extends CarwashController
{
    public function index(WorkerDirectory $workers): JsonResponse
    {
        return response()->json([
            'data' => $workers->list($this->company())->map(fn (User $user) => [
                'publicId' => $user->public_id,
                'name' => $user->name,
                'isActive' => true,
            ])->values(),
        ]);
    }
}
