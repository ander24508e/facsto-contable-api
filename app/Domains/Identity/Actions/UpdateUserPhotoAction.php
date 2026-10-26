<?php

namespace App\Domains\Identity\Actions;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class UpdateUserPhotoAction
{
    public function execute(User $user, UploadedFile $photo): User
    {
        $path = 'users/'.$user->public_id.'/profile/'.Str::uuid().'.'.$photo->extension();
        $previousPath = $user->profile_photo_path;

        Storage::disk('private')->putFileAs(dirname($path), $photo, basename($path));

        try {
            $user->forceFill(['profile_photo_path' => $path])->save();
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }

        if ($previousPath !== null) {
            Storage::disk('private')->delete($previousPath);
        }

        return $user->refresh();
    }
}
