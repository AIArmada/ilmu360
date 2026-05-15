<?php

namespace App\Filament\Resources\Audits\Schemas;

use App\Models\Audit;
use App\Support\Auditing\AuditValuePresenter;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class AuditInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('AuditTabs')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Details')
                            ->schema([
                                TextEntry::make('user.name')
                                    ->label('User')
                                    ->placeholder('System'),
                                TextEntry::make('created_at')
                                    ->dateTime('M j, Y H:i:s')
                                    ->label('Recorded At'),
                                TextEntry::make('auditable_type')
                                    ->label('Record Type')
                                    ->formatStateUsing(static fn (?string $state): string => self::humanizeType($state)),
                                TextEntry::make('auditable_id')
                                    ->label('Record ID')
                                    ->placeholder('—'),
                                TextEntry::make('event')
                                    ->label('Event')
                                    ->formatStateUsing(static fn (?string $state): string => filled($state) ? Str::headline($state) : '—'),
                                TextEntry::make('url')
                                    ->label('URL')
                                    ->placeholder('—'),
                                TextEntry::make('ip_address')
                                    ->label('IP Address')
                                    ->placeholder('—'),
                                TextEntry::make('user_agent')
                                    ->label('User Agent')
                                    ->placeholder('—')
                                    ->columnSpanFull(),
                                TextEntry::make('tags')
                                    ->label('Context')
                                    ->placeholder('App')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                        Tab::make('Before')
                            ->schema([
                                KeyValueEntry::make('old_values')
                                    ->label('Before')
                                    ->keyLabel('Field')
                                    ->state(static fn (Audit $record): array => AuditValuePresenter::values($record, 'old_values')),
                            ]),
                        Tab::make('After')
                            ->schema([
                                KeyValueEntry::make('new_values')
                                    ->label('After')
                                    ->keyLabel('Field')
                                    ->state(static fn (Audit $record): array => AuditValuePresenter::values($record, 'new_values')),
                            ]),
                    ]),
            ]);
    }

    private static function humanizeType(?string $state): string
    {
        if (! filled($state)) {
            return '—';
        }

        return Str::headline(Str::afterLast($state, '\\'));
    }
}
