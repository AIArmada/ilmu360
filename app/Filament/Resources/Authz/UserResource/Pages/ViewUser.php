<?php

declare(strict_types=1);

namespace App\Filament\Resources\Authz\UserResource\Pages;

use AIArmada\FilamentAuthz\Facades\Authz;
use App\Filament\Resources\Authz\UserResource;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Institutions\InstitutionResource;
use App\Filament\Resources\References\ReferenceResource;
use App\Filament\Resources\Speakers\SpeakerResource;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\User;
use App\Support\ApiDocumentation\ApiDocumentationUrlResolver;
use App\Support\Timezone\UserDateTimeFormatter;
use Carbon\CarbonInterface;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected string $view = 'filament.resources.authz.user-resource.pages.view-user';

    public string $apiTokenName = '';

    public ?string $newApiToken = null;

    #[\Override]
    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->reloadUserRecord();
    }

    public function createApiToken(): void
    {
        $validated = $this->validate([
            'apiTokenName' => ['required', 'string', 'max:255'],
        ], attributes: [
            'apiTokenName' => 'Token name',
        ]);

        $this->newApiToken = $this->userRecord()
            ->createToken(trim((string) $validated['apiTokenName']))
            ->plainTextToken;
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

        $deleted = $this->userRecord()->tokens()->whereKey($normalizedTokenId)->delete();

        if ($deleted === 0) {
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
        return $this->userRecord()
            ->tokens()
            ->latest('created_at')
            ->get(['id', 'name', 'created_at', 'last_used_at'])
            ->map(fn (PersonalAccessToken $token): array => [
                'id' => $token->getKey(),
                'name' => $token->name,
                'created_at' => $this->formatTokenTimestamp($token->created_at),
                'last_used_at' => $token->last_used_at instanceof CarbonInterface
                    ? $this->formatTokenTimestamp($token->last_used_at)
                    : null,
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

        $user->loadMissing([
            'roles',
            'savedEvents' => fn ($query) => $query
                ->with(['institution:id,name', 'venue:id,name'])
                ->orderBy('event_saves.created_at', 'desc'),
            'goingEvents' => fn ($query) => $query
                ->with(['institution:id,name', 'venue:id,name'])
                ->orderBy('event_attendees.created_at', 'desc'),
            'eventCheckins' => fn ($query) => $query
                ->with(['event:id,title,status,starts_at', 'verifiedBy:id,name'])
                ->orderByDesc('checked_in_at'),
            'registrations' => fn ($query) => $query
                ->with(['event:id,title,status,starts_at'])
                ->latest(),
            'followingInstitutions' => fn ($query) => $query->orderBy('name'),
            'followingSpeakers' => fn ($query) => $query->orderBy('name'),
            'followingReferences' => fn ($query) => $query->orderBy('title'),
            'eventSubmissions' => fn ($query) => $query
                ->with(['event:id,title,status,starts_at'])
                ->latest(),
            'institutions' => fn ($query) => $query->orderBy('name'),
            'speakers' => fn ($query) => $query->orderBy('name'),
            'memberEvents' => fn ($query) => $query
                ->with(['institution:id,name', 'venue:id,name'])
                ->orderByDesc('starts_at'),
            'references' => fn ($query) => $query->orderBy('title'),
            'savedSearches' => fn ($query) => $query->latest(),
        ]);

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

    public function eventUrl(?Event $event): ?string
    {
        return $event instanceof Event ? EventResource::getUrl('view', ['record' => $event]) : null;
    }

    public function institutionUrl(?Institution $institution): ?string
    {
        return $institution instanceof Institution ? InstitutionResource::getUrl('view', ['record' => $institution]) : null;
    }

    public function speakerUrl(?Speaker $speaker): ?string
    {
        return $speaker instanceof Speaker ? SpeakerResource::getUrl('view', ['record' => $speaker]) : null;
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

    public function entityStatusBadgeColor(?string $state): string
    {
        return match ($state) {
            'verified' => 'success',
            'pending' => 'warning',
            'rejected' => 'danger',
            'unverified' => 'gray',
            default => 'gray',
        };
    }

    public function registrationStatusBadgeColor(?string $state): string
    {
        return match ($state) {
            'registered', 'attended' => 'success',
            'cancelled', 'no_show' => 'danger',
            default => 'gray',
        };
    }

    public function humanLabel(?string $value): string
    {
        return filled($value) ? Str::headline($value) : '-';
    }

    private function userRecord(): User
    {
        /** @var User $user */
        $user = $this->getRecord();

        return $user;
    }

    private function formatTokenTimestamp(?CarbonInterface $timestamp): string
    {
        if (! $timestamp instanceof CarbonInterface) {
            return '-';
        }

        return UserDateTimeFormatter::translatedFormat($timestamp, 'j M Y, h:i A');
    }
}
