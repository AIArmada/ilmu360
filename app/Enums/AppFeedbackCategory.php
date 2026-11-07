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
     * Selectable categories for new feedback. UiUx is retained for existing
     * records but merged into FeatureRequest in the public form.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $category) {
            if ($category === self::UiUx) {
                continue;
            }

            $options[$category->value] = $category->getLabel();
        }

        return $options;
    }
}
