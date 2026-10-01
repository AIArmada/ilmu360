<?php

namespace App\Forms;

use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;

class ReferenceContributionFormSchema
{
    /**
     * @return array<int, Component>
     */
    public static function components(bool $includeMedia = false): array
    {
        $components = [
            Section::make(__('Reference Details'))
                ->schema([
                    ...ReferenceFormSchema::fields('publication_year'),
                    Textarea::make('description')
                        ->label(__('Description'))
                        ->rows(5)
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make(__('Links'))
                ->schema([
                    SharedFormSchema::socialMediaRepeater('Add additional links for this reference (e.g. YouTube video, Blog article, etc.)'),
                ]),
        ];

        if ($includeMedia) {
            array_splice($components, 1, 0, [
                Section::make(__('Imagery'))
                    ->schema([
                        SpatieMediaLibraryFileUpload::make('front_cover')
                            ->label(__('Front Cover'))
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
                            ->label(__('Back Cover'))
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
                            ->label(__('Gallery'))
                            ->collection('gallery')
                            ->multiple()
                            ->reorderable()
                            ->image()
                            ->conversion('gallery_thumb')
                            ->responsiveImages()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
        }

        return $components;
    }
}
