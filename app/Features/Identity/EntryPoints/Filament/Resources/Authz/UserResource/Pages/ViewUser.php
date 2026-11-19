<?php

declare(strict_types=1);

namespace App\Features\Identity\EntryPoints\Filament\Resources\Authz\UserResource\Pages;

use AIArmada\Authz\Facades\Authz;
use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Engagement\Api\EngagementSubjectType;
use App\Features\Engagement\Api\EventEngagement;
use App\Features\Engagement\Api\SubjectFollows;
use App\Features\Events\EntryPoints\Filament\Resources\Events\EventResource;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\EventSubmission;
use App\Features\Identity\Api\AccountAdministration;
use App\Features\Identity\Api\AccountToken;
use App\Features\Identity\EntryPoints\Filament\Resources\Authz\UserResource;
use App\Features\Institutions\EntryPoints\Filament\Resources\Institutions\InstitutionResource;
use App\Features\Institutions\Models\Institution;
use App\Features\Membership\Queries\MemberDirectorySummary;
use App\Features\Persons\EntryPoints\Filament\Resources\Persons\PersonResource;
use App\Features\Persons\Models\Person;
use App\Features\References\EntryPoints\Filament\Resources\References\ReferenceResource;
use App\Features\References\Models\Reference;
use App\Features\Registration\Models\EventCheckin;
use App\Features\Registration\Models\Registration;
use App\Features\SavedSearches\Api\SavedSearchReader;
use App\Features\SavedSearches\Api\SavedSearchSnapshot;
use App\Models\User;
use App\Platform\ApiDocumentation\ApiDocumentationUrlResolver;
use App\Platform\Time\UserDateTimeFormatter;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ViewUser extends ViewRecord
{
    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    #[\Override]
    protected static string $resource = UserResource::class;

    #[\Override]
    protected Width|string|null $maxContentWidth = Width::Full;

    #[\Override]
    protected string $view = 'filament.resources.authz.user-resource.pages.view-user';

    public string $apiTokenName = '';

    public ?string $newApiToken = null;

    #[\Override]
    public function mount(int|string $record): void
    {
        parent::mount($record);
        OwnerContext::setForRequest(null);

        $this->reloadUserRecord();
    }

    public function createApiToken(): void
    {
        $validated = $this->validate([
            'apiTokenName' => ['required', 'string', 'max:255'],
        ], attributes: [
            'apiTokenName' => 'Token name',
        ]);

        $this->newApiToken = app(AccountAdministration::class)
            ->createToken((string) $this->userRecord()->getKey(), trim((string) $validated['apiTokenName']));
        $this->apiTokenName = '';

        Notification::make()
            ->title('API access token created.')
            ->body('Copy the token now. It will not be shown again after you leave this page.')
            ->success()
            ->persistent()
            ->send();
    }

    public function revokeApiToken(mixed $tokenId): void
    {
        $normalizedTokenId = filter_var($tokenId, FILTER_VALIDATE_INT);

        if ($normalizedTokenId === false) {
            throw new NotFoundHttpException;
        }

        if (! app(AccountAdministration::class)->revokeToken((string) $this->userRecord()->getKey(), $normalizedTokenId)) {
            throw new NotFoundHttpException;
        }

        Notification::make()
            ->title('API access token revoked.')
            ->success()
            ->send();
    }

    /**
     * @return list<array{id: int|string, name: string, created_at: string, last_used_at: string|null}>
     */
    public function apiTokens(): array
    {
        return collect(app(AccountAdministration::class)->tokens((string) $this->userRecord()->getKey()))
            ->map(fn (AccountToken $token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'created_at' => $this->formatTokenTimestamp(
                    $token->createdAt instanceof \DateTimeImmutable ? CarbonImmutable::instance($token->createdAt) : null
                ),
                'last_used_at' => $this->formatTokenTimestamp(
                    $token->lastUsedAt instanceof \DateTimeImmutable ? CarbonImmutable::instance($token->lastUsedAt) : null
                ),
            ])
            ->all();
    }

    public function apiDocsUrl(): string
    {
        return app(ApiDocumentationUrlResolver::class)->docsUrl();
    }

    private function reloadUserRecord(): void
    {
        /** @var User $user */
        $user = User::query()->findOrFail($this->getRecord()->getKey());

        OwnerContext::withOwner(null, function () use ($user): void {
            $user->loadMissing(['roles']);
        });

        $this->record = $user;
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    /**
     * @return list<string>
     */
    public function roleNames(): array
    {
        /** @var list<string> $roles */
        $roles = Authz::withScope(
            null,
            fn (): array => $this->userRecord()->getRoleNames()->sort()->values()->all(),
            $this->userRecord(),
        );

        return $roles;
    }

    /** @return list<SavedSearchSnapshot> */
    #[Computed]
    public function savedSearches(): array
    {
        $user = $this->userRecord();

        return app(SavedSearchReader::class)->forOwner($user->getMorphClass(), (string) $user->getKey());
    }

    /**
     * @return Collection<int, Institution>
     */
    #[Computed]
    public function memberInstitutions(): Collection
    {
        return app(MemberDirectorySummary::class)->institutions($this->userRecord());
    }

    /**
     * @return Collection<int, Person>
     */
    #[Computed]
    public function memberPersons(): Collection
    {
        return app(MemberDirectorySummary::class)->persons($this->userRecord());
    }

    /**
     * @return Collection<int, Event>
     */
    #[Computed]
    public function memberEvents(): Collection
    {
        return app(MemberDirectorySummary::class)->events($this->userRecord());
    }

    /**
     * @return Collection<int, Reference>
     */
    #[Computed]
    public function memberReferences(): Collection
    {
        return app(MemberDirectorySummary::class)->references($this->userRecord());
    }

    /**
     * @return Collection<int, Event>
     */
    #[Computed]
    public function savedEvents(): Collection
    {
        $user = $this->userRecord();

        return OwnerContext::withOwner(null, function () use ($user): Collection {
            $ids = app(EventEngagement::class)->savedEventIds((string) $user->getKey());

            return $ids === []
                ? new Collection
                : Event::query()->whereKey($ids)->with(['institution:id,name', 'venue:id,name'])->get();
        });
    }

    /**
     * @return Collection<int, Event>
     */
    #[Computed]
    public function goingEvents(): Collection
    {
        $user = $this->userRecord();

        return OwnerContext::withOwner(null, function () use ($user): Collection {
            $ids = app(EventEngagement::class)->goingEventIds((string) $user->getKey());

            if ($ids === []) {
                return new Collection;
            }

            return Event::query()
                ->whereKey($ids)
                ->with(['institution:id,name', 'venue:id,name'])
                ->get();
        });
    }

    /**
     * @return Collection<int, EventCheckin>
     */
    #[Computed]
    public function eventCheckins(): Collection
    {
        $user = $this->userRecord();

        return OwnerContext::withOwner(null, fn (): Collection => EventCheckin::query()
            ->where('attendee_id', (string) $user->getKey())
            ->with(['event', 'verifiedBy:id,name'])
            ->orderByDesc('checked_in_at')
            ->get());
    }

    /**
     * @return Collection<int, Registration>
     */
    #[Computed]
    public function registrations(): Collection
    {
        $user = $this->userRecord();

        return OwnerContext::withOwner(null, fn (): Collection => Registration::query()
            ->where('registrant_type', $user->getMorphClass())
            ->where('registrant_id', (string) $user->getKey())
            ->with('event')
            ->latest()
            ->get());
    }

    /**
     * @return Collection<int, EventSubmission>
     */
    #[Computed]
    public function eventSubmissions(): Collection
    {
        $user = $this->userRecord();

        return OwnerContext::withOwner(null, fn (): Collection => EventSubmission::query()
            ->where('submitter_type', $user->getMorphClass())
            ->where('submitter_id', (string) $user->getKey())
            ->with('event')
            ->latest()
            ->get());
    }

    /**
     * @return Collection<int, Institution>
     */
    #[Computed]
    public function followingInstitutions(): Collection
    {
        return $this->followedSubjects(EngagementSubjectType::Institution, Institution::class, 'name');
    }

    /**
     * @return Collection<int, Person>
     */
    #[Computed]
    public function followingPersons(): Collection
    {
        return $this->followedSubjects(EngagementSubjectType::Person, Person::class, 'name');
    }

    /**
     * @return Collection<int, Reference>
     */
    #[Computed]
    public function followingReferences(): Collection
    {
        return $this->followedSubjects(EngagementSubjectType::Reference, Reference::class, 'title');
    }

    public function eventUrl(?Event $event): ?string
    {
        return $event instanceof Event ? EventResource::getUrl('view', ['record' => $event]) : null;
    }

    public function institutionUrl(?Institution $institution): ?string
    {
        return $institution instanceof Institution ? InstitutionResource::getUrl('view', ['record' => $institution]) : null;
    }

    public function personUrl(?Person $person): ?string
    {
        return $person instanceof Person ? PersonResource::getUrl('view', ['record' => $person]) : null;
    }

    public function referenceUrl(?Reference $reference): ?string
    {
        return $reference instanceof Reference ? ReferenceResource::getUrl('edit', ['record' => $reference]) : null;
    }

    /**
     * @param  array<string, mixed>|null  $filters
     */
    public function formatSavedSearchFilters(?array $filters): string
    {
        if (! is_array($filters) || $filters === []) {
            return '-';
        }

        $parts = collect($filters)
            ->filter(fn (mixed $value): bool => filled($value) || (is_array($value) && $value !== []))
            ->map(function (mixed $value, string $key): string {
                $formattedValue = match (true) {
                    is_array($value) => implode(', ', array_map(static fn (mixed $item): string => (string) $item, $value)),
                    is_bool($value) => $value ? 'Yes' : 'No',
                    default => (string) $value,
                };

                return Str::headline($key).': '.$formattedValue;
            })
            ->values()
            ->all();

        if ($parts === []) {
            return '-';
        }

        return implode(' | ', array_slice($parts, 0, 5));
    }

    public function formattedSearchNotify(?string $state): string
    {
        return filled($state) ? Str::headline($state) : '-';
    }

    public function eventStatusBadgeColor(?string $state): string
    {
        return match ($state) {
            'approved' => 'success',
            'pending', 'needs_changes' => 'warning',
            'cancelled', 'rejected' => 'danger',
            'draft' => 'gray',
            default => 'gray',
        };
    }

    public function entityStatusBadgeColor(BackedEnum|string|null $state): string
    {
        $value = $state instanceof BackedEnum ? (string) $state->value : $state;

        return match ($value) {
            'verified' => 'success',
            'pending' => 'warning',
            'rejected' => 'danger',
            'inactive' => 'gray',
            default => 'gray',
        };
    }

    public function registrationStatusBadgeColor(?string $state): string
    {
        return match ($state) {
            'confirmed', 'checked_in', 'completed' => 'success',
            'pending', 'waitlisted' => 'warning',
            'cancelled', 'no_show', 'rejected', 'refunded', 'expired' => 'danger',
            default => 'gray',
        };
    }

    public function humanLabel(BackedEnum|string|null $value): string
    {
        $value = $value instanceof BackedEnum ? (string) $value->value : $value;

        return filled($value) ? Str::headline($value) : '-';
    }

    private function userRecord(): User
    {
        /** @var User $user */
        $user = $this->getRecord();

        return $user;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $modelClass
     * @return Collection<int, TModel>
     */
    private function followedSubjects(EngagementSubjectType $subjectType, string $modelClass, string $orderColumn): Collection
    {
        $user = $this->userRecord();

        return OwnerContext::withOwner(null, function () use ($subjectType, $modelClass, $orderColumn, $user): Collection {
            $ids = app(SubjectFollows::class)->followedSubjectIds((string) $user->getKey(), $subjectType);

            return $ids === []
                ? new Collection
                : $modelClass::query()->whereKey($ids)->orderBy($orderColumn)->get();
        });
    }

    private function formatTokenTimestamp(?CarbonInterface $timestamp): string
    {
        if (! $timestamp instanceof CarbonInterface) {
            return '-';
        }

        return UserDateTimeFormatter::translatedFormat($timestamp, 'j M Y, h:i A');
    }
}
