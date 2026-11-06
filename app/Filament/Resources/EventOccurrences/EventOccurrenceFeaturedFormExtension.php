<?php

declare(strict_types=1);

namespace App\Filament\Resources\EventOccurrences;

use AIArmada\Events\Models\EventOccurrence;
use AIArmada\FilamentEvents\Contracts\EventOccurrenceFormExtension;
use App\Models\User;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;

final class EventOccurrenceFeaturedFormExtension implements EventOccurrenceFormExtension
{
    /**
     * @return array<int, Component>
     */
    public function components(): array
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->hasGlobalAdminAccess()) {
            return [];
        }

        return [
            Section::make('Homepage')
                ->description('Choose whether this occurrence is promoted on the homepage.')
                ->schema([
                    Toggle::make('is_featured')
                        ->label('Feature this occurrence')
                        ->helperText(fn (?EventOccurrence $record): string => $record instanceof EventOccurrence
                            && ($record->ends_at ?? $record->starts_at)?->isPast()
                                ? 'This occurrence is in the past and will not appear on the homepage.'
                                : 'Featured upcoming public occurrences appear on the homepage; past occurrences do not.'),
                ]),
        ];
    }
}
