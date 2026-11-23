<?php

namespace App\Shared\Media;

use Closure;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Schemas\Components\Section;

final class EventMediaForm
{
    /**
     * @param  list<string>|null  $mediaFields
     * @param  (Closure(SpatieMediaLibraryFileUpload): void)|null  $configureMedia
     */
    public static function section(?array $mediaFields = null, ?Closure $configureMedia = null): Section
    {
        $fields = [
            SpatieMediaLibraryFileUpload::make('cover')
                ->label(__('Gambar Cover Majlis'))
                ->collection('cover')
                ->image()
                ->imageEditor()
                ->imageAspectRatio('16:9')
                ->automaticallyOpenImageEditorForAspectRatio()
                ->imageEditorAspectRatioOptions(['16:9', null])
                ->automaticallyCropImagesToAspectRatio()
                ->rules(['dimensions:ratio=16/9'])
                ->conversion('thumb')
                ->responsiveImages()
                ->helperText(__('Untuk paparan laman web dan aplikasi. Wajib 16:9, tanpa maklumat yang terlalu padat.')),
            SpatieMediaLibraryFileUpload::make('poster')
                ->label(__('Poster Hebahan'))
                ->collection('poster')
                ->image()
                ->imageEditor()
                ->imageAspectRatio('3:4')
                ->automaticallyOpenImageEditorForAspectRatio()
                ->imageEditorAspectRatioOptions(['3:4', null])
                ->automaticallyCropImagesToAspectRatio()
                ->rules(['dimensions:ratio=3/4'])
                ->conversion('poster_thumb')
                ->responsiveImages()
                ->helperText(__('Untuk hebahan WhatsApp, Instagram, Facebook, dan saluran luar. Wajib portrait 3:4 dan boleh mengandungi maklumat penuh.')),
            SpatieMediaLibraryFileUpload::make('gallery')
                ->label(__('Galeri'))
                ->collection('gallery')
                ->multiple()
                ->reorderable()
                ->maxFiles(10)
                ->image()
                ->imageEditor()
                ->conversion('gallery_thumb')
                ->responsiveImages()
                ->helperText(__('Gambar tambahan untuk galeri majlis.')),
        ];

        if ($mediaFields !== null) {
            $fields = array_filter($fields, fn (SpatieMediaLibraryFileUpload $field): bool => in_array($field->getName(), $mediaFields, true));

            foreach ($fields as $field) {
                if (! $field->isMultiple()) {
                    $field->deletable(false);
                }
            }
        }

        if ($configureMedia instanceof Closure) {
            foreach ($fields as $field) {
                $configureMedia($field);
            }
        }

        return Section::make(__('Media'))
            ->schema(array_values($fields))
            ->columns(['default' => 1, 'sm' => 2]);
    }
}
