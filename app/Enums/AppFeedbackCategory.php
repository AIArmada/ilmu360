<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AppFeedbackCategory: string implements HasColor, HasLabel
{
    case Bug = 'bug';
    case FeatureRequest = 'feature_request';
    case UiUx = 'ui_ux';
    case Performance = 'performance';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bug => __('app-feedback.categories.bug'),
            self::FeatureRequest => __('app-feedback.categories.feature_request'),
            self::UiUx => __('app-feedback.categories.ui_ux'),
            self::Performance => __('app-feedback.categories.performance'),
            self::Other => __('app-feedback.categories.other'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Bug => 'danger',
            self::FeatureRequest => 'success',
            self::UiUx => 'info',
            self::Performance => 'warning',
            self::Other => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $category) {
            $options[$category->value] = $category->getLabel();
        }

        return $options;
    }
}
