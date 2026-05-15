<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum EventKeyPersonRole: string implements HasLabel
{
    case Speaker = 'speaker';
    case Moderator = 'moderator';
    case PersonInCharge = 'person_in_charge';
    case Imam = 'imam';
    case Khatib = 'khatib';
    case Bilal = 'bilal';

    public function getLabel(): string
    {
        return match ($this) {
            self::Speaker => __('Penceramah'),
            self::Moderator => __('Moderator'),
            self::PersonInCharge => __('PIC / Penyelaras'),
            self::Imam => __('Imam'),
            self::Khatib => __('Khatib'),
            self::Bilal => __('Bilal'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function nonSpeakerOptions(): array
    {
        return collect(self::cases())
            ->reject(fn (self $role): bool => $role === self::Speaker)
            ->mapWithKeys(fn (self $role): array => [$role->value => $role->getLabel()])
            ->all();
    }
}
