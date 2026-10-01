<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DonationChannelStatus: string implements HasLabel
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
}
