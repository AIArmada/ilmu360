<?php

declare(strict_types=1);

namespace App\Filament\Resources\CommunityReleases\Pages;

use App\Actions\Share\PublishCommunityReleaseAction;
use App\Filament\Resources\CommunityReleases\CommunityReleaseResource;
use App\Models\CommunityRelease;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ListCommunityReleases extends ListRecords
{
    protected static string $resource = CommunityReleaseResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish_release')
                ->label('Publish month')
                ->icon(Heroicon::OutlinedMegaphone)
                ->color('primary')
                ->visible(fn (): bool => auth()->user()?->can('publish', CommunityRelease::class) ?? false)
                ->modalHeading('Publish community release')
                ->modalDescription('Fixed calendar months only, published at least 7 days after month end. Threshold-gated and immutable: sparse months refuse with insufficient data, repeats return the published row.')
                ->schema([
                    TextInput::make('month')
                        ->label('Month (YYYY-MM)')
                        ->placeholder('2026-05')
                        ->required()
                        ->regex('/^\\d{4}-(0[1-9]|1[0-2])$/'),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();
                    abort_unless($user?->can('publish', CommunityRelease::class) ?? false, 403);

                    try {
                        $release = PublishCommunityReleaseAction::run(
                            (string) $data['month'],
                            'user',
                            (string) $user->getKey(),
                        );
                    } catch (InvalidArgumentException $exception) {
                        if (str_starts_with($exception->getMessage(), 'Insufficient data')) {
                            Notification::make()
                                ->title('Insufficient data')
                                ->body($exception->getMessage())
                                ->warning()
                                ->send();

                            return;
                        }

                        throw ValidationException::withMessages(['month' => $exception->getMessage()]);
                    }

                    Notification::make()
                        ->title($release->wasRecentlyCreated ? 'Community release published' : 'Month already published')
                        ->body("Sharers {$release->sharer_count}, participants {$release->participant_count}.")
                        ->success()
                        ->send();
                }),
        ];
    }
}
