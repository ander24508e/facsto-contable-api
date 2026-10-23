<?php

namespace App\Domains\Identity\Enums;

enum UserStatus: string
{
    case ACTIVE = 'ACTIVE';
    case DISABLED = 'DISABLED';
}
