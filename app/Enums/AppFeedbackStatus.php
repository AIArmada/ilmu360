<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AppFeedbackStatus: string implements HasColor, HasLabel
{
    case New = 'new';
    case Triaged = 'triaged';
    case Resolved = 'resolved';
    case Declined = 'declined';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => __('app-feedback.statuses.new'),
            self::Triaged => __('app-feedback.statuses.triaged'),
            self::Resolved => __('app-feedback.statuses.resolved'),
            self::Declined => __('app-feedback.statuses.declined'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'warning',
            self::Triaged => 'info',
            self::Resolved => 'success',
            self::Declined => 'gray',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Resolved, self::Declined], true);
    }

    public function isOpen(): bool
    {
        return $this === self::New;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $status) {
            $options[$status->value] = $status->getLabel();
        }

        return $options;
    }
}
