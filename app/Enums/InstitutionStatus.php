<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum InstitutionStatus: string implements HasLabel
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Inactive = 'inactive';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Verified => __('Verified'),
            self::Rejected => __('Rejected'),
            self::Inactive => __('Inactive'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Verified => 'success',
            self::Rejected => 'danger',
            self::Inactive => 'gray',
        };
    }

    /**
     * @return list<self>
     */
    public static function publiclyVisible(): array
    {
        return [self::Verified, self::Pending];
    }

    /**
     * @return list<string>
     */
    public static function publiclyVisibleValues(): array
    {
        return [self::Verified->value, self::Pending->value];
    }
}
