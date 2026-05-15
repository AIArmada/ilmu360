<?php

namespace App\Filament\Ahli\Resources\Events;

use App\Enums\EventKeyPersonRole;
use App\Filament\Ahli\Resources\Events\Pages\EditEvent;
use App\Filament\Ahli\Resources\Events\Pages\ListEvents;
use App\Filament\Ahli\Resources\Events\Pages\ViewEvent;
use App\Filament\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\Events\EventResource as AdminEventResource;
use App\Filament\Resources\Events\RelationManagers\ChildEventsRelationManager;
use App\Filament\Resources\Events\RelationManagers\EventUsersRelationManager;
use App\Filament\Resources\Events\RelationManagers\MediaLinksRelationManager;
use App\Filament\Resources\Events\RelationManagers\MemberInvitationsRelationManager;
use App\Filament\Resources\Events\RelationManagers\ModerationReviewsRelationManager;
use App\Filament\Resources\Events\RelationManagers\RegistrationsRelationManager;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class EventResource extends AdminEventResource
{
    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?string $navigationLabel = 'Events';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'events';

    #[\Override]
    public static function table(Table $table): Table
    {
        return parent::table($table)
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('view_public')
                    ->label('View Public')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Event $record): string => route('events.show', $record))
                    ->openUrlInNewTab(),
            ])
            ->toolbarActions([]);
    }

    /**
     * @return Builder<Event>
     */
    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Event> $query */
        $query = parent::getEloquentQuery();

        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $eventQuery) use ($user): void {
            // User's own submissions (direct ownership and submission records).
            $eventQuery
                ->where('events.user_id', $user->id)
                ->orWhere('events.submitter_id', $user->id)
                ->orWhereIn(
                    'events.id',
                    EventSubmission::query()
                        ->where('submitted_by', $user->id)
                        ->select('event_id')
                )
                ->orWhereIn(
                    'events.id',
                    $user->memberEvents()->select('events.id')
                )
                ->orWhereIn(
                    'events.id',
                    EventKeyPerson::query()
                        ->whereIn('speaker_id', $user->speakers()->select('speakers.id'))
                        ->where('role', EventKeyPersonRole::Speaker->value)
                        ->select('event_id')
                );

            // Events organized by institutions where user is a member.
            $eventQuery->orWhere(function (Builder $institutionOrganizerQuery) use ($user): void {
                $institutionOrganizerQuery
                    ->whereIn('events.organizer_type', [Institution::class, 'institution'])
                    ->whereIn(
                        'events.organizer_id',
                        $user->institutions()->select('institutions.id')
                    );
            });

            // Events organized by speakers where user is a member.
            $eventQuery->orWhere(function (Builder $speakerOrganizerQuery) use ($user): void {
                $speakerOrganizerQuery
                    ->whereIn('events.organizer_type', [Speaker::class, 'speaker'])
                    ->whereIn(
                        'events.organizer_id',
                        $user->speakers()->select('speakers.id')
                    );
            });

            // Any event linked to a member institution, including speaker-organized and legacy records.
            $eventQuery->orWhere(function (Builder $institutionLinkedQuery) use ($user): void {
                $institutionLinkedQuery
                    ->whereIn(
                        'events.institution_id',
                        $user->institutions()->select('institutions.id')
                    );
            });
        });
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            ChildEventsRelationManager::class,
            'media_links' => MediaLinksRelationManager::class,
            'event_users' => EventUsersRelationManager::class,
            'member_invitations' => MemberInvitationsRelationManager::class,
            'moderation_reviews' => ModerationReviewsRelationManager::class,
            'registrations' => RegistrationsRelationManager::class,
            AuditsRelationManager::class,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListEvents::route('/'),
            'view' => ViewEvent::route('/{record}'),
            'edit' => EditEvent::route('/{record}/edit'),
        ];
    }
}
