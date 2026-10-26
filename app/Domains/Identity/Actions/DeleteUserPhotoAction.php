<?php

namespace App\Domains\Identity\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

final class DeleteUserPhotoAction
{
    public function execute(User $user): User
    {
        $path = $user->profile_photo_path;
        if ($path === null) {
            return $user;
        }

        $user->forceFill(['profile_photo_path' => null])->save();
        Storage::disk('private')->delete($path);

        return $user->refresh();
    }
}
