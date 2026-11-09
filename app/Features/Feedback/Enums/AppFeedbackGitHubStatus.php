<?php

declare(strict_types=1);

namespace App\Features\Feedback\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AppFeedbackGitHubStatus: string implements HasColor, HasLabel
{
    case NotRequested = 'not_requested';
    case Pending = 'pending';
    case Created = 'created';
    case Unknown = 'unknown';

    public function getLabel(): string
    {
        return match ($this) {
            self::NotRequested => __('app-feedback.github.not_requested'),
            self::Pending => __('app-feedback.github.pending'),
            self::Created => __('app-feedback.github.created'),
            self::Unknown => __('app-feedback.github.unknown'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NotRequested => 'gray',
            self::Pending => 'warning',
            self::Created => 'success',
            self::Unknown => 'danger',
        };
    }
}
