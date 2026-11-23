<?php

namespace App\Features\Contributions\EntryPoints\Filament\Forms;

use AIArmada\FilamentContacting\Schemas\SocialProfileRepeaterSchema;
use App\Features\References\Api\Filament\ReferenceQuickCreate;
use Closure;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;

class ReferenceContributionFormSchema
{
    /**
     * @param  list<string>|null  $mediaFields
     * @param  (Closure(SpatieMediaLibraryFileUpload): void)|null  $configureMedia
     * @return array<int, Component>
     */
    public static function components(
        bool $includeMedia = false,
        ?array $mediaFields = null,
        ?Closure $configureMedia = null,
    ): array {
        $components = [
            Section::make(__('Reference Details'))
                ->schema([
                    ...app(ReferenceQuickCreate::class)->referenceFields('publication_year'),
                    Textarea::make('description')
                        ->label(__('Description'))
                        ->rows(5)
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make(__('Links'))
                ->schema([
                    SocialProfileRepeaterSchema::make(helperText: 'Add additional links for this reference (e.g. YouTube video, Blog article, etc.)'),
                ]),
        ];

        if ($includeMedia && $mediaFields !== []) {
            $fields = [
                SpatieMediaLibraryFileUpload::make('front_cover')
                    ->label(__('Front Cover'))
                    ->collection('front_cover')
                    ->image()
                    ->imageEditor()
                    ->imageAspectRatio('3:4')
                    ->rules(['dimensions:ratio=3/4'])
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
                    ->rules(['dimensions:ratio=3/4'])
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
            ];

            if ($mediaFields !== null) {
                $fields = array_filter($fields, fn (SpatieMediaLibraryFileUpload $field): bool => in_array($field->getName(), $mediaFields, true));
            }

            if ($configureMedia !== null) {
                foreach ($fields as $field) {
                    $configureMedia($field);
                }
            }

            array_splice($components, 1, 0, [
                Section::make(__('Imagery'))
                    ->schema(array_values($fields))
                    ->columns(['default' => 1, 'sm' => 2]),
            ]);
        }

        return $components;
    }
}
