<?php

namespace App\Filament\Resources\Reports\Schemas;

use App\Actions\Reports\ResolveReportCategoryOptionsAction;
use App\Actions\Reports\ResolveReportEntityMetadataAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ReportForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Report')
                    ->components([
                        Select::make('entity_type')
                            ->options(app(ResolveReportEntityMetadataAction::class)->options())
                            ->required(),
                        TextInput::make('entity_id')
                            ->required()
                            ->maxLength(36),
                        Select::make('category')
                            ->options(app(ResolveReportCategoryOptionsAction::class)->handle())
                            ->required(),
                        Textarea::make('description')
                            ->columnSpanFull()
                            ->maxLength(2000),
                        SpatieMediaLibraryFileUpload::make('evidence')
                            ->label('Evidence Files')
                            ->collection('evidence')
                            ->multiple()
                            ->reorderable()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                            ->maxFiles(8)
                            ->conversion('thumb')
                            ->openable()
                            ->downloadable()
                            ->helperText('Upload screenshots, photos, or PDFs to support this report.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Resolution')
                    ->components([
                        Select::make('status')
                            ->options([
                                'open' => 'Open',
                                'triaged' => 'Triaged',
                                'resolved' => 'Resolved',
                                'dismissed' => 'Dismissed',
                            ])
                            ->required(),
                        Select::make('reporter_id')
                            ->relationship('reporter', 'email')
                            ->searchable()
                            ->preload(),
                        Select::make('handled_by')
                            ->relationship('handler', 'email')
                            ->searchable()
                            ->preload(),
                        Textarea::make('resolution_note')
                            ->columnSpanFull()
                            ->maxLength(2000),
                    ])
                    ->columns(2),
            ]);
    }
}
