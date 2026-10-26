<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Identity\Actions\ChangeUserPasswordAction;
use App\Domains\Identity\Actions\DeleteUserPhotoAction;
use App\Domains\Identity\Actions\UpdateUserAccountAction;
use App\Domains\Identity\Actions\UpdateUserPhotoAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ChangePasswordRequest;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Http\Requests\Account\UploadProfilePhotoRequest;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AccountController extends Controller
{
    public function updateProfile(UpdateProfileRequest $request, UpdateUserAccountAction $update): UserResource
    {
        $attributes = array_intersect_key($request->validated(), array_flip(['name', 'email', 'phone']));
        foreach (['name', 'email'] as $optionalIdentityField) {
            if (array_key_exists($optionalIdentityField, $attributes) && $attributes[$optionalIdentityField] === null) {
                unset($attributes[$optionalIdentityField]);
            }
        }

        $newPassword = $request->validated('password');
        $user = $update->execute($request->user(), $attributes, $newPassword);

        if ($newPassword !== null && $request->hasSession()) {
            $request->session()->regenerate();
        }

        return new UserResource($user);
    }

    public function changePassword(ChangePasswordRequest $request, ChangeUserPasswordAction $change): JsonResponse
    {
        $change->execute($request->user(), $request->string('password')->toString());
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json(['data' => ['message' => 'Contraseña actualizada.']]);
    }

    public function photo(Request $request): StreamedResponse
    {
        $path = $request->user()->profile_photo_path;
        abort_unless($path && Storage::disk('private')->exists($path), 404);

        return Storage::disk('private')->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function updatePhoto(UploadProfilePhotoRequest $request, UpdateUserPhotoAction $update): UserResource
    {
        return new UserResource($update->execute($request->user(), $request->file('photo')));
    }

    public function deletePhoto(Request $request, DeleteUserPhotoAction $delete): UserResource
    {
        return new UserResource($delete->execute($request->user()));
    }
}
