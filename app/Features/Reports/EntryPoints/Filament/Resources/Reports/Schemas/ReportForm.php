<?php

namespace App\Features\Reports\EntryPoints\Filament\Resources\Reports\Schemas;

use App\Features\Reports\Actions\ResolveReportCategoryOptionsAction;
use App\Features\Reports\Actions\ResolveReportEntityMetadataAction;
use App\Platform\Media\MediaEvidence;
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
                        MediaEvidence::configureUpload(
                            SpatieMediaLibraryFileUpload::make(MediaEvidence::COLLECTION)
                                ->label('Evidence Files')
                                ->openable()
                                ->downloadable()
                                ->helperText('Upload screenshots, photos, or PDFs to support this report.'),
                        )
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
