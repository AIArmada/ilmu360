<?php

namespace App\Platform\Ui\Forms;

use AIArmada\Contacting\Enums\SocialPlatform;
use AIArmada\Contacting\Support\SocialProfileConfig;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class SocialFields
{
    /**
     * Social media repeater schema.
     */
    public static function socialMediaRepeater(string $helperText = 'Add social media links'): Repeater
    {
        return Repeater::make('social_media')
            ->label(__('Social Media'))
            ->schema([
                Grid::make(2)->schema([
                    Select::make('platform')
                        ->label(__('Platform'))
                        ->required()
                        ->options(FormOptions::translatedOptions(SocialPlatform::options()))
                        ->searchable()
                        ->live(),
                    TextInput::make('label')
                        ->label(__('Label'))
                        ->maxLength(255)
                        ->helperText(__('Optional name shown alongside this link.'))
                        ->placeholder(__('Main page, Official channel')),
                ]),
                TextInput::make('handle')
                    ->label(__('Handle'))
                    ->required()
                    ->maxLength(255)
                    ->helperText(__('Enter the username or full profile link.'))
                    ->placeholder(__('username / https://...'))
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                        if ($state === null || $state === '' || ! str_contains($state, '://')) {
                            return;
                        }

                        $platform = $get('platform');
                        if ($platform === null || $platform === '') {
                            return;
                        }

                        $platformValue = $platform instanceof SocialPlatform ? $platform->value : $platform;
                        $extracted = app(SocialProfileConfig::class)->extractHandle($platformValue, $state);

                        if ($extracted !== null) {
                            $set('handle', $extracted);
                        }
                    })
                    ->visible(fn (Get $get): bool => self::socialHandleVisible($get)),
                Placeholder::make('profile_url')
                    ->label(__('Profile URL'))
                    ->content(function (Get $get): ?string {
                        $platform = $get('platform');
                        $handle = $get('handle');

                        if (! is_string($handle) || $handle === '') {
                            return null;
                        }

                        $platformValue = $platform instanceof SocialPlatform ? $platform->value : (string) $platform;

                        return app(SocialProfileConfig::class)->buildUrl($platformValue, $handle);
                    })
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => self::socialHandleVisible($get)),
                TextInput::make('url')
                    ->label(__('URL'))
                    ->required()
                    ->url()
                    ->maxLength(255)
                    ->placeholder(__('https://...'))
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => self::socialUrlVisible($get)),
                Grid::make(2)->schema([
                    Toggle::make('is_primary')
                        ->label(__('Primary'))
                        ->fixIndistinctState(),
                    Toggle::make('is_public')
                        ->label(__('Public'))
                        ->default(true),
                ])->columnSpanFull(),
            ])
            ->collapsible()
            ->defaultItems(0)
            ->addActionLabel(__('Add Social Media'))
            ->helperText(__($helperText));
    }

    private static function socialHandleVisible(Get $get): bool
    {
        $platform = $get('platform');
        $value = $platform instanceof SocialPlatform ? $platform->value : $platform;

        return is_string($value)
            && $value !== SocialPlatform::Website->value
            && $value !== SocialPlatform::Other->value;
    }

    private static function socialUrlVisible(Get $get): bool
    {
        $platform = $get('platform');
        $value = $platform instanceof SocialPlatform ? $platform->value : $platform;

        return $value === SocialPlatform::Website->value || $value === SocialPlatform::Other->value;
    }
}
