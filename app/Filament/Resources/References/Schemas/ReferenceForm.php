<?php

namespace App\Filament\Resources\References\Schemas;

use AIArmada\Contacting\Enums\SocialPlatform;
use AIArmada\Contacting\Support\SocialProfileConfig;
use App\Enums\ReferencePartType;
use App\Enums\ReferenceType;
use App\Models\Reference;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('author')
                            ->maxLength(255),
                        Select::make('type')
                            ->options(ReferenceType::class)
                            ->default(ReferenceType::Book->value)
                            ->required()
                            ->live(),
                        Select::make('parent_id')
                            ->label('Parent Book')
                            ->helperText('Select a root book when this reference represents a specific jilid, bahagian, or volume.')
                            ->options(fn (?Reference $record): array => Reference::query()
                                ->where('type', ReferenceType::Book->value)
                                ->whereNull('parent_id')
                                ->when($record instanceof Reference && $record->exists, fn ($query) => $query->whereKeyNot($record->getKey()))
                                ->orderBy('title')
                                ->pluck('title', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->live()
                            ->visible(fn (Get $get): bool => in_array($get('type'), [ReferenceType::Book, ReferenceType::Book->value], true))
                            ->dehydrated(fn (Get $get): bool => in_array($get('type'), [ReferenceType::Book, ReferenceType::Book->value], true)),
                        Select::make('part_type')
                            ->label('Part Type')
                            ->options(ReferencePartType::class)
                            ->default(ReferencePartType::Jilid->value)
                            ->visible(fn (Get $get): bool => filled($get('parent_id')))
                            ->dehydrated(fn (Get $get): bool => filled($get('parent_id'))),
                        TextInput::make('part_number')
                            ->label('Part Number')
                            ->placeholder('2')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => filled($get('parent_id')))
                            ->dehydrated(fn (Get $get): bool => filled($get('parent_id'))),
                        TextInput::make('part_label')
                            ->label('Part Label')
                            ->helperText('Optional display label, e.g. Jilid 2 or Bahagian Akhir.')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => filled($get('parent_id')))
                            ->dehydrated(fn (Get $get): bool => filled($get('parent_id'))),
                        TextInput::make('year')
                            ->maxLength(255),
                        TextInput::make('publisher')
                            ->maxLength(255),
                        Toggle::make('is_canonical')
                            ->label('Canonical / Official')
                            ->helperText('Is this a standard reference?'),
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
                            ->orderColumn('order_column')
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
