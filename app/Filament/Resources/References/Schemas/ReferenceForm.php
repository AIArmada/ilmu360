<?php

namespace App\Filament\Resources\References\Schemas;

use AIArmada\Contacting\Enums\SocialPlatform;
use AIArmada\Contacting\Support\SocialProfileConfig;
use App\Forms\ReferenceFormSchema;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ReferenceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Reference Details')
                    ->components([
                        ...ReferenceFormSchema::fields(publicOnly: false),
                        Select::make('status')
                            ->options([
                                'pending' => 'Pending',
                                'verified' => 'Verified',
                                'inactive' => 'Inactive',
                            ])
                            ->default('verified')
                            ->required(),
                    ])->columns(2),
                Section::make('Imagery')
                    ->components([
                        SpatieMediaLibraryFileUpload::make('front_cover')
                            ->label('Front Cover')
                            ->collection('front_cover')
                            ->image()
                            ->imageEditor()
                            ->imageAspectRatio('3:4')
                            ->automaticallyOpenImageEditorForAspectRatio()
                            ->imageEditorAspectRatioOptions(['3:4'])
                            ->automaticallyCropImagesToAspectRatio()
                            ->conversion('thumb')
                            ->responsiveImages(),
                        SpatieMediaLibraryFileUpload::make('back_cover')
                            ->label('Back Cover')
                            ->collection('back_cover')
                            ->image()
                            ->imageEditor()
                            ->imageAspectRatio('3:4')
                            ->automaticallyOpenImageEditorForAspectRatio()
                            ->imageEditorAspectRatioOptions(['3:4'])
                            ->automaticallyCropImagesToAspectRatio()
                            ->conversion('thumb')
                            ->responsiveImages(),
                        SpatieMediaLibraryFileUpload::make('gallery')
                            ->label('Gallery')
                            ->collection('gallery')
                            ->multiple()
                            ->image()
                            ->imageEditor()
                            ->conversion('gallery_thumb')
                            ->responsiveImages()
                            ->maxFiles(10)
                            ->columnSpanFull(),
                    ])->columns(2),
                Section::make('Links')
                    ->components([
                        Repeater::make('socialProfiles')
                            ->relationship()
                            ->schema([
                                Select::make('platform')
                                    ->options(SocialPlatform::options())
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->columnSpan(1),
                                TextInput::make('handle')
                                    ->label('Username / Handle')
                                    ->required()
                                    ->placeholder('username or https://...')
                                    ->live()
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
                                    ->columnSpan(1)
                                    ->visible(function (Get $get): bool {
                                        $platform = $get('platform');
                                        if ($platform === null || $platform === '') {
                                            return false;
                                        }
                                        $value = $platform instanceof SocialPlatform ? $platform->value : $platform;

                                        return $value !== SocialPlatform::Website->value && $value !== SocialPlatform::Other->value;
                                    }),
                                Placeholder::make('profile_url')
                                    ->label('Profile URL')
                                    ->content(function (Get $get): ?string {
                                        $platform = $get('platform');
                                        $handle = $get('handle');
                                        if (! is_string($handle) || $handle === '') {
                                            return null;
                                        }
                                        $value = $platform instanceof SocialPlatform ? $platform->value : $platform;

                                        return app(SocialProfileConfig::class)->buildUrl($value, $handle);
                                    })
                                    ->columnSpanFull()
                                    ->visible(function (Get $get): bool {
                                        $platform = $get('platform');
                                        if ($platform === null || $platform === '') {
                                            return false;
                                        }
                                        $value = $platform instanceof SocialPlatform ? $platform->value : $platform;

                                        return $value !== SocialPlatform::Website->value && $value !== SocialPlatform::Other->value;
                                    }),
                                TextInput::make('url')
                                    ->label('URL')
                                    ->required()
                                    ->url()
                                    ->maxLength(255)
                                    ->columnSpanFull()
                                    ->visible(function (Get $get): bool {
                                        $platform = $get('platform');
                                        if ($platform === null || $platform === '') {
                                            return false;
                                        }
                                        $value = $platform instanceof SocialPlatform ? $platform->value : $platform;

                                        return $value === SocialPlatform::Website->value || $value === SocialPlatform::Other->value;
                                    }),
                            ])
                            ->columns(2)
                            ->orderColumn('sort_order')
                            ->collapsible()
                            ->defaultItems(0)
                            ->addActionLabel('Add Link')
                            ->itemLabel(function (array $state): ?string {
                                $platform = $state['platform'] ?? null;

                                if ($platform instanceof SocialPlatform) {
                                    return $platform->label();
                                }

                                if (is_string($platform)) {
                                    return SocialPlatform::tryFrom($platform)?->label() ?? $platform;
                                }

                                return null;
                            }),
                    ]),
                Section::make('Description')
                    ->components([
                        Textarea::make('description')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
