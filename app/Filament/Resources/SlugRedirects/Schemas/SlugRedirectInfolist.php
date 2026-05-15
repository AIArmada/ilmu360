<?php

namespace App\Filament\Resources\SlugRedirects\Schemas;

use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\SlugRedirect;
use App\Models\Speaker;
use App\Models\Venue;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SlugRedirectInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Redirect')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextEntry::make('redirectable_type')
                                    ->label('Subject Type')
                                    ->badge()
                                    ->formatStateUsing(static fn (?string $state): string => filled($state) ? Str::headline($state) : 'Unknown'),
                                TextEntry::make('subject_label')
                                    ->label('Subject')
                                    ->state(static fn (SlugRedirect $record): string => self::subjectLabel($record->redirectable)),
                                TextEntry::make('source_slug')
                                    ->label('Source Slug'),
                                TextEntry::make('destination_slug')
                                    ->label('Destination Slug'),
                                TextEntry::make('source_path')
                                    ->label('From')
                                    ->columnSpanFull(),
                                TextEntry::make('destination_path')
                                    ->label('To')
                                    ->columnSpanFull(),
                                TextEntry::make('first_visited_at')
                                    ->label('First Visited')
                                    ->dateTime()
                                    ->placeholder('Not captured'),
                                TextEntry::make('last_redirected_at')
                                    ->label('Last Redirect')
                                    ->dateTime()
                                    ->placeholder('Never'),
                                TextEntry::make('redirect_count')
                                    ->label('Redirect Count')
                                    ->numeric(),
                                TextEntry::make('created_at')
                                    ->label('Recorded At')
                                    ->dateTime(),
                                TextEntry::make('updated_at')
                                    ->label('Updated At')
                                    ->dateTime(),
                            ]),
                    ]),
            ]);
    }

    private static function subjectLabel(?Model $model): string
    {
        return match (true) {
            $model instanceof Event => $model->title,
            $model instanceof Institution => $model->name,
            $model instanceof Speaker => $model->formatted_name,
            $model instanceof Reference => $model->title,
            $model instanceof Venue => $model->name,
            default => 'Deleted record',
        };
    }
}
