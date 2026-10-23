<?php

namespace App\Domains\Companies\Enums;

enum CompanyRole: string
{
    case OWNER = 'OWNER';
    case ADMIN = 'ADMIN';
    case BILLER = 'BILLER';
    case VIEWER = 'VIEWER';

    public function canManage(self $target): bool
    {
        return match ($this) {
            self::OWNER => true,
            self::ADMIN => in_array($target, [self::BILLER, self::VIEWER], true),
            self::BILLER, self::VIEWER => false,
        };
    }
}
