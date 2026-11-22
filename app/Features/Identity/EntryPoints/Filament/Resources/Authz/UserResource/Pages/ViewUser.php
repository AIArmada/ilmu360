<?php

declare(strict_types=1);

namespace App\Features\Identity\EntryPoints\Filament\Resources\Authz\UserResource\Pages;

use AIArmada\Authz\Facades\Authz;
use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Engagement\Api\EngagementSubjectType;
use App\Features\Engagement\Api\EventEngagement;
use App\Features\Engagement\Api\SubjectFollows;
use App\Features\Events\Api\Data\EventSubmissionCard;
use App\Features\Events\Api\EventSubmissions;
use App\Features\Events\Api\EventScheduleReads;
use App\Features\Events\Api\Data\AdminEventCard;
use App\Features\Identity\Api\AccountAdministration;
use App\Features\Identity\Api\AccountToken;
use App\Features\Identity\EntryPoints\Filament\Resources\Authz\UserResource;
use App\Features\Institutions\Api\InstitutionDirectoryRecord;
use App\Features\Institutions\Api\InstitutionLookup;
use App\Features\Membership\Api\AccountMembershipDirectory;
use App\Features\Membership\Api\MemberSubjectRecord;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Persons\Api\PersonDirectoryRecord;
use App\Features\Persons\Api\PersonLookup;
use App\Features\References\Api\ReferenceLookup;
use App\Features\References\Api\ReferenceSummary;
use App\Features\Registration\Api\Data\CheckInSnapshot;
use App\Features\Registration\Api\Data\RegistrationSnapshot;
use App\Features\Registration\Api\RegistrationLookup;
use App\Features\SavedSearches\Api\SavedSearchReader;
use App\Features\SavedSearches\Api\SavedSearchSnapshot;
use App\Models\User;
use App\Platform\Admin\AdminResourceLink;
use App\Platform\ApiDocumentation\ApiDocumentationUrlResolver;
use App\Platform\Time\UserDateTimeFormatter;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Illuminate\Support\Collection;
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
     * @return \Illuminate\Support\Collection<int, MemberSubjectRecord>
     */
    #[Computed]
    public function memberInstitutions(): \Illuminate\Support\Collection
    {
        return collect(app(AccountMembershipDirectory::class)->subjects(
            (string) $this->userRecord()->getKey(),
            MemberSubjectType::Institution,
        ));
    }

    /**
     * @return \Illuminate\Support\Collection<int, MemberSubjectRecord>
     */
    #[Computed]
    public function memberPersons(): \Illuminate\Support\Collection
    {
        return collect(app(AccountMembershipDirectory::class)->subjects(
            (string) $this->userRecord()->getKey(),
            MemberSubjectType::Person,
        ));
    }

    /**
     * @return \Illuminate\Support\Collection<int, MemberSubjectRecord>
     */
    #[Computed]
    public function memberEvents(): \Illuminate\Support\Collection
    {
        return collect(app(AccountMembershipDirectory::class)->subjects(
            (string) $this->userRecord()->getKey(),
            MemberSubjectType::Event,
        ));
    }

    /**
     * @return \Illuminate\Support\Collection<int, MemberSubjectRecord>
     */
    #[Computed]
    public function memberReferences(): \Illuminate\Support\Collection
    {
        return collect(app(AccountMembershipDirectory::class)->subjects(
            (string) $this->userRecord()->getKey(),
            MemberSubjectType::Reference,
        ));
    }

    /**
     * @return Collection<int, AdminEventCard>
     */
    #[Computed]
    public function savedEvents(): Collection
    {
        $user = $this->userRecord();

        return OwnerContext::withOwner(null, function () use ($user): Collection {
            $ids = app(EventEngagement::class)->savedEventIds((string) $user->getKey());

            /** @var Collection<int, AdminEventCard> $events */
            $events = $ids === []
                ? new Collection
                : collect(app(EventScheduleReads::class)->adminEventCardsForIds($ids));

            return $events;
        });
    }

    /**
     * @return Collection<int, AdminEventCard>
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

            /** @var Collection<int, AdminEventCard> $events */
            $events = collect(app(EventScheduleReads::class)->adminEventCardsForIds($ids));

            return $events;
        });
    }

    /** @return \Illuminate\Support\Collection<int, CheckInSnapshot> */
    #[Computed]
    public function eventCheckins(): \Illuminate\Support\Collection
    {
        return OwnerContext::withOwner(null, fn (): \Illuminate\Support\Collection => collect(
            app(RegistrationLookup::class)->checkInsForAccount((string) $this->userRecord()->getKey()),
        ));
    }

    /** @return \Illuminate\Support\Collection<int, RegistrationSnapshot> */
    #[Computed]
    public function registrations(): \Illuminate\Support\Collection
    {
        return OwnerContext::withOwner(null, fn (): \Illuminate\Support\Collection => collect(
            app(RegistrationLookup::class)->registrationsForAccount((string) $this->userRecord()->getKey()),
        ));
    }

    /** @return \Illuminate\Support\Collection<int, EventSubmissionCard> */
    #[Computed]
    public function eventSubmissions(): \Illuminate\Support\Collection
    {
        $user = $this->userRecord();

        return OwnerContext::withOwner(null, fn (): \Illuminate\Support\Collection => collect(
            app(EventSubmissions::class)->submissionsForAccount((string) $user->getKey()),
        ));
    }

    /**
     * @return \Illuminate\Support\Collection<int, InstitutionDirectoryRecord>
     */
    #[Computed]
    public function followingInstitutions(): \Illuminate\Support\Collection
    {
        return $this->followedSubjects(
            EngagementSubjectType::Institution,
            fn (array $ids): \Illuminate\Support\Collection => collect(app(InstitutionLookup::class)->institutionsByIds($ids)),
        );
    }

    /**
     * @return \Illuminate\Support\Collection<int, PersonDirectoryRecord>
     */
    #[Computed]
    public function followingPersons(): \Illuminate\Support\Collection
    {
        return $this->followedSubjects(
            EngagementSubjectType::Person,
            fn (array $ids): \Illuminate\Support\Collection => collect(app(PersonLookup::class)->personsByIds($ids)),
        );
    }

    /**
     * @return \Illuminate\Support\Collection<int, ReferenceSummary>
     */
    #[Computed]
    public function followingReferences(): \Illuminate\Support\Collection
    {
        return $this->followedSubjects(
            EngagementSubjectType::Reference,
            fn (array $ids): \Illuminate\Support\Collection => collect(app(ReferenceLookup::class)->referencesByIds($ids)),
        );
    }

    public function institutionUrl(?InstitutionDirectoryRecord $institution): ?string
    {
        return $institution instanceof InstitutionDirectoryRecord ? AdminResourceLink::to('institution', 'view', $institution->id) : null;
    }

    public function personUrl(?PersonDirectoryRecord $person): ?string
    {
        return $person instanceof PersonDirectoryRecord ? AdminResourceLink::to('person', 'view', $person->id) : null;
    }

    public function referenceUrl(?ReferenceSummary $reference): ?string
    {
        return $reference instanceof ReferenceSummary ? AdminResourceLink::to('reference', 'edit', $reference->id) : null;
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
     * @param  callable(list<string>): \Illuminate\Support\Collection<int, mixed>  $fetch
     * @return \Illuminate\Support\Collection<int, mixed>
     */
    private function followedSubjects(EngagementSubjectType $subjectType, callable $fetch): \Illuminate\Support\Collection
    {
        $user = $this->userRecord();

        return OwnerContext::withOwner(null, function () use ($subjectType, $fetch, $user): \Illuminate\Support\Collection {
            $ids = app(SubjectFollows::class)->followedSubjectIds((string) $user->getKey(), $subjectType);

            if ($ids === []) {
                return new Collection;
            }

            /** @var \Illuminate\Support\Collection<int, mixed> $subjects */
            $subjects = $fetch($ids);

            return $subjects;
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
