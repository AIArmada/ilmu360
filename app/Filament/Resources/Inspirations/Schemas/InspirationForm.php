<?php

namespace App\Filament\Resources\Inspirations\Schemas;

use App\Enums\InspirationCategory;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InspirationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Inspiration Details')
                    ->components([
                        Select::make('category')
                            ->options(InspirationCategory::class)
                            ->enum(InspirationCategory::class)
                            ->required()
                            ->native(false),

                        Select::make('locale')
                            ->options(config('app.supported_locales'))
                            ->required()
                            ->default('ms')
                            ->native(false),

                        TextInput::make('title')
                            ->required()
                            ->maxLength(255),

                        SpatieMediaLibraryFileUpload::make('main')
                            ->label('Image')
                            ->collection('main')
                            ->image()
                            ->imageEditor()
                            ->responsiveImages()
                            ->conversion('thumb')
                            ->helperText('Optional: if uploaded, this image will be shown in the sidebar instead of text')
                            ->columnSpanFull(),

                        RichEditor::make('content')
                            ->required()
                            ->json()
                            ->columnSpanFull(),

                        TextInput::make('source')
                            ->maxLength(255)
                            ->placeholder('e.g., Surah Al-Baqarah 2:153, HR Bukhari')
                            ->helperText('Attribution or reference for the content'),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ])->columns(2),
            ]);
    }
}
