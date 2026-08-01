<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events;

use AIArmada\FilamentEvents\Contracts\EventFormExtension;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;

final class EventMediaFormExtension implements EventFormExtension
{
    /**
     * @return array<int, Component>
     */
    public function components(): array
    {
        return [
            Section::make('Media')
                ->schema([
                    SpatieMediaLibraryFileUpload::make('cover')
                        ->label('Cover image')
                        ->collection('cover')
                        ->image()
                        ->imageEditor()
                        ->imageAspectRatio('16:9')
                        ->automaticallyOpenImageEditorForAspectRatio()
                        ->imageEditorAspectRatioOptions(['16:9', null])
                        ->automaticallyCropImagesToAspectRatio()
                        ->conversion('thumb')
                        ->responsiveImages(),
                    SpatieMediaLibraryFileUpload::make('poster')
                        ->label('Poster')
                        ->collection('poster')
                        ->image()
                        ->imageEditor()
                        ->imageAspectRatio('3:4')
                        ->automaticallyOpenImageEditorForAspectRatio()
                        ->imageEditorAspectRatioOptions(['3:4', null])
                        ->automaticallyCropImagesToAspectRatio()
                        ->rules(['dimensions:ratio=3/4'])
                        ->conversion('poster_thumb')
                        ->responsiveImages(),
                    SpatieMediaLibraryFileUpload::make('gallery')
                        ->label('Gallery')
                        ->collection('gallery')
                        ->multiple()
                        ->reorderable()
                        ->maxFiles(10)
                        ->image()
                        ->imageEditor()
                        ->conversion('gallery_thumb')
                        ->responsiveImages(),
                ])
                ->columns(2),
        ];
    }
}
