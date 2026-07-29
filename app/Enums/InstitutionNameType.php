<?php

declare(strict_types=1);

namespace App\Enums;

use AIArmada\CommerceSupport\Traits\HasLabelOptions;

enum InstitutionNameType: string
{
    use HasLabelOptions;

    case Official = 'official';
    case Nickname = 'nickname';
    case Abbreviation = 'abbreviation';
    case Local = 'local';
    case Historical = 'historical';

    public function label(): string
    {
        return match ($this) {
            self::Official => 'Official',
            self::Nickname => 'Nickname',
            self::Abbreviation => 'Abbreviation',
            self::Local => 'Local',
            self::Historical => 'Historical',
        };
    }
}
