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

    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'Propietario',
            self::ADMIN => 'Administrador',
            self::BILLER => 'Facturador',
            self::VIEWER => 'Consulta',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::OWNER => 'Administra todos los roles y la empresa.',
            self::ADMIN => 'Administra usuarios con rol Facturador o Consulta.',
            self::BILLER => 'Realiza operaciones comerciales y fiscales permitidas.',
            self::VIEWER => 'Consulta la información disponible.',
        };
    }
}
