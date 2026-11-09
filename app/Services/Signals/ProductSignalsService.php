<?php

declare(strict_types=1);

namespace App\Services\Signals;

use AIArmada\Communications\Models\NotificationInbox;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSubmission;
use AIArmada\Referrals\Models\ReferralInvite;
use AIArmada\Rewards\Models\RewardEnrollment;
use AIArmada\Rewards\Models\RewardProgram;
use AIArmada\Signals\Contracts\SignalEventIngestor;
use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Shared\Signals\SignalRecorder;
use App\Support\Signals\ProductSignalsClientContext;
use Illuminate\Http\Request;
use Throwable;

final readonly class ProductSignalsService implements SignalRecorder
{
    public function __construct(
        private SignalEventIngestor $ingestSignalEvent,
        private ProductSignalsClientContext $clientContext,
        private ProductSignalSchemaRegistry $schemaRegistry,
    ) {}

    public function recordLogin(User $user, Request $request, string $method, bool $createdAccount = false): ?SignalEvent
    {
        return $this->record(
            eventName: 'auth.login',
            eventCategory: 'auth',
            actorId: $user->getKey(),
            properties: [
                'method' => $method,
                'created_account' => $createdAccount,
            ],
            request: $request,
        );
    }

    public function recordSignup(User $user, ?Request $request = null): ?SignalEvent
    {
        return $this->record(
            eventName: 'auth.signup.completed',
            eventCategory: 'auth',
            actorId: $user->getKey(),
            properties: [
                'has_email' => filled($user->email),
                'has_phone' => filled($user->phone),
            ],
            request: $request,
        );
    }

    public function recordPasswordResetCompleted(User $user, ?Request $request = null): ?SignalEvent
    {
        return $this->record(
            eventName: 'auth.password_reset.completed',
            eventCategory: 'auth',
            actorId: $user->getKey(),
            request: $request,
        );
    }

    public function recordEmailVerified(User $user, ?Request $request = null): ?SignalEvent
    {
        return $this->record(
            eventName: 'auth.email_verified',
            eventCategory: 'auth',
            actorId: $user->getKey(),
            request: $request,
        );
    }

    public function recordInstitutionMapsPlaceSelected(
        User $user,
        bool $hasWebsite,
        int $socialProfileCount,
        ?Request $request = null,
    ): ?SignalEvent {
        return $this->record(
            eventName: 'contribution.institution_maps_place_selected',
            eventCategory: 'contribution',
            actorId: $user->getKey(),
            properties: [
                'has_website' => $hasWebsite,
                'social_profile_count' => $socialProfileCount,
            ],
            request: $request,
        );
    }

    public function recordRewardProgramEnrolled(
        RewardProgram $program,
        RewardEnrollment $enrollment,
        User $user,
        ?Request $request = null,
        string $surface = 'member-portal',
    ): ?SignalEvent {
        return $this->record(
            eventName: 'rewards.program_enrolled',
            eventCategory: 'engagement',
            actorId: $user->getKey(),
            properties: [
                'program_id' => (string) $program->getKey(),
                'program_family' => $program->family_key,
                'enrollment_id' => (string) $enrollment->getKey(),
                'surface' => $surface,
            ],
            request: $request,
        );
    }

    public function recordReferralInviteCreated(
        ReferralInvite $invite,
        User $user,
        ?Request $request = null,
        string $surface = 'member-portal',
    ): ?SignalEvent {
        return $this->record(
            eventName: 'referrals.invite_created',
            eventCategory: 'engagement',
            actorId: $user->getKey(),
            properties: [
                'program_id' => (string) $invite->program_id,
                'invite_id' => (string) $invite->getKey(),
                'surface' => $surface,
            ],
            request: $request,
        );
    }

    public function recordReferralInviteAccepted(
        ReferralInvite $invite,
        User $user,
        ?Request $request = null,
        string $surface = 'invite-landing',
    ): ?SignalEvent {
        return $this->record(
            eventName: 'referrals.invite_accepted',
            eventCategory: 'engagement',
            actorId: $user->getKey(),
            properties: [
                'program_id' => (string) $invite->program_id,
                'invite_id' => (string) $invite->getKey(),
                'surface' => $surface,
            ],
            request: $request,
        );
    }

    public function recordModerationTransition(
        Event $event,
        string $action,
        ?User $moderator = null,
        ?string $previousStatus = null,
        ?string $reasonCode = null,
        ?string $note = null,
        ?Request $request = null,
    ): ?SignalEvent {
        return $this->record(
            eventName: 'moderation.event.'.$action,
            eventCategory: 'moderation',
            actorId: $moderator?->getKey(),
            properties: [
                'event_id' => (string) $event->getKey(),
                'moderation_action' => $action,
                'previous_status' => $previousStatus,
                'current_status' => (string) $event->status,
                'moderator_id' => $moderator?->getKey(),
                'reason_code' => $reasonCode,
                'has_note' => filled($note),
            ],
            request: $request,
        );
    }

    public function recordOccurrenceModerationTransition(
        Event $event,
        EventSubmission $submission,
        EventOccurrence $occurrence,
        string $action,
        User $moderator,
        string $previousStatus,
        string $currentStatus,
        ?Request $request = null,
        ?string $reasonCode = null,
        ?string $note = null,
    ): ?SignalEvent {
        return $this->record(
            eventName: 'moderation.event.occurrence_'.$action,
            eventCategory: 'moderation',
            actorId: $moderator->getKey(),
            properties: [
                'event_id' => (string) $event->getKey(),
                'event_occurrence_id' => (string) $occurrence->getKey(),
                'submission_id' => (string) $submission->getKey(),
                'moderation_action' => $action,
                'previous_status' => $previousStatus,
                'current_status' => $currentStatus,
                'moderator_id' => (string) $moderator->getKey(),
                'reason_code' => $reasonCode,
                'has_note' => filled($note),
            ],
            request: $request,
        );
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function recordEventCoverGenerated(
        Event $event,
        ?User $user = null,
        ?Request $request = null,
        array $properties = [],
    ): ?SignalEvent {
        return $this->record(
            eventName: 'admin.event_cover.generated',
            eventCategory: 'admin',
            actorId: $user?->getKey(),
            properties: [
                'event_id' => (string) $event->getKey(),
                'event_status' => (string) $event->status,
                ...$properties,
            ],
            request: $request,
        );
    }

    /**
     * One curated outcome for an actual own-facilities change: omitted and
     * no-op maps produce no event, so callers record only after a provided
     * map changed and the whole save succeeded.
     *
     * @param  array<string, bool>|null  $previousFacilities
     * @param  array<string, bool>|null  $currentFacilities
     */
    public function recordInstitutionFacilitiesUpdated(
        Institution $institution,
        ?array $previousFacilities,
        ?array $currentFacilities,
        ?User $actor = null,
        ?Request $request = null,
    ): ?SignalEvent {
        $previous = $previousFacilities ?? [];
        $current = $currentFacilities ?? [];

        $changed = [];

        foreach (array_unique([...array_keys($previous), ...array_keys($current)]) as $code) {
            if (($previous[$code] ?? null) !== ($current[$code] ?? null)) {
                $changed[] = $code;
            }
        }

        sort($changed);

        if ($changed === []) {
            return null;
        }

        $enabledCount = count(array_filter($current));

        return $this->record(
            eventName: 'admin.institution_facilities.updated',
            eventCategory: 'admin',
            actorId: $actor?->getKey(),
            properties: [
                'institution_id' => (string) $institution->getKey(),
                'changed_codes' => $changed,
                'enabled_count' => $enabledCount,
                'disabled_count' => count($current) - $enabledCount,
                'cleared' => $currentFacilities === null,
            ],
            request: $request,
        );
    }

    public function recordReferenceQuickCreated(Reference $reference, ?Request $request = null): ?SignalEvent
    {
        $user = $request?->user();

        return $this->record(
            eventName: 'reference.quick_created',
            eventCategory: 'contribution',
            actorId: $user instanceof User ? $user->getKey() : null,
            properties: [
                'reference_id' => (string) $reference->getKey(),
                'record_kind' => $reference->recordKindValue(),
                'parent_id' => $reference->parentIdValue(),
                'status' => (string) $reference->status,
                'source' => 'event_reference_picker',
            ],
            request: $request,
        );
    }

    public function recordAuthorQuickCreated(Person $person, ?Request $request = null): ?SignalEvent
    {
        $user = $request?->user();

        return $this->record(
            eventName: 'author.quick_created',
            eventCategory: 'contribution',
            actorId: $user instanceof User ? $user->getKey() : null,
            properties: [
                'person_id' => (string) $person->getKey(),
                'status' => (string) $person->status,
                'source' => 'reference_author_picker',
            ],
            request: $request,
        );
    }

    public function recordNotificationRead(NotificationInbox $message, User $user, ?Request $request = null): ?SignalEvent
    {
        return $this->record(
            eventName: 'notification.read',
            eventCategory: 'notification',
            actorId: $user->getKey(),
            properties: [
                'notification_id' => (string) $message->getKey(),
                'family' => $this->enumValue($message->family),
                'trigger' => $this->enumValue($message->trigger),
                'action_url' => $message->data['action_url'] ?? null,
            ],
            request: $request,
        );
    }

    public function recordNotificationsReadAll(User $user, int $updatedCount, ?Request $request = null): ?SignalEvent
    {
        if ($updatedCount < 1) {
            return null;
        }

        return $this->record(
            eventName: 'notification.read_all',
            eventCategory: 'notification',
            actorId: $user->getKey(),
            properties: [
                'updated_count' => $updatedCount,
            ],
            request: $request,
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function recordSearchExecuted(
        ?User $user,
        ?Request $request,
        string $surface,
        ?string $query,
        array $filters = [],
        ?int $resultCount = null,
        ?string $savedSearchId = null,
    ): ?SignalEvent {
        $normalizedQuery = is_string($query) ? trim($query) : null;
        $normalizedFilters = array_filter($filters, function (mixed $value): bool {
            if ($value === null || $value === '') {
                return false;
            }

            if (is_array($value)) {
                return $value !== [];
            }

            return true;
        });

        if (($normalizedQuery === null || $normalizedQuery === '') && $normalizedFilters === []) {
            return null;
        }

        $hasQuery = $normalizedQuery !== null && $normalizedQuery !== '';

        return $this->record(
            eventName: $hasQuery ? 'search.executed' : 'listing.filtered',
            eventCategory: $hasQuery ? 'search' : 'discovery',
            actorId: $user?->getKey(),
            properties: [
                'interaction_type' => $hasQuery ? 'search' : 'filter',
                'surface' => $surface,
                'query' => $normalizedQuery,
                'filter_keys' => array_values(array_map(static fn (string $key): string => $key, array_keys($normalizedFilters))),
                'filters' => $normalizedFilters,
                'result_count' => $resultCount,
                'saved_search_id' => $savedSearchId,
            ],
            request: $request,
        );
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(
        string $eventName,
        string $eventCategory,
        ?string $actorId = null,
        array $properties = [],
        ?Request $request = null,
        ?string $anonymousId = null,
    ): ?SignalEvent {
        try {
            $trackedProperty = TrackedProperty::query()
                ->withoutOwnerScope()
                ->whereNull('owner_type')
                ->whereNull('owner_id')
                ->where('slug', (string) config('signals.integrations.browser.tracked_property.slug', 'ilmu360'))
                ->first();

            if (! $trackedProperty instanceof TrackedProperty) {
                return null;
            }

            $payload = [
                'event_name' => $eventName,
                'event_category' => $eventCategory,
                'external_id' => $actorId,
                'anonymous_id' => $anonymousId ?? $this->resolveAnonymousId($request),
                'session_identifier' => $this->resolveSessionIdentifier($request),
                'path' => $this->resolvePath($request),
                'url' => $request?->fullUrl(),
                'referrer' => $request?->headers->get('referer'),
                'source' => $request?->query('utm_source'),
                'medium' => $request?->query('utm_medium'),
                'campaign' => $request?->query('utm_campaign'),
                'content' => $request?->query('utm_content'),
                'term' => $request?->query('utm_term'),
                'properties' => $this->normalizeProperties($eventName, $request, $properties),
            ];

            return $this->ingestSignalEvent->handle($trackedProperty, $payload, trusted: true);
        } catch (Throwable $exception) {
            report($exception);
            logger()->warning('Signals product telemetry skipped after ingestion failure.', [
                'event_name' => $eventName,
                'event_category' => $eventCategory,
                'route_name' => $request?->route()?->getName(),
                'user_id' => $actorId,
            ]);

            return null;
        }
    }

    private function resolveSessionIdentifier(?Request $request): ?string
    {
        if (! $request instanceof Request) {
            return null;
        }

        $cookieIdentifier = trim((string) $request->cookies->get((string) config('signals.integrations.browser.identifiers.session_cookie_name', 'sig_sid')));

        if ($cookieIdentifier !== '') {
            return $cookieIdentifier;
        }

        if (! $request->hasSession()) {
            return null;
        }

        $session = $request->session();

        return $session->isStarted() ? $session->getId() : null;
    }

    private function resolveAnonymousId(?Request $request): ?string
    {
        if (! $request instanceof Request) {
            return null;
        }

        $cookieAnonymousId = trim((string) $request->cookies->get((string) config('signals.integrations.browser.identifiers.visitor_cookie_name', 'sig_vid')));

        if ($cookieAnonymousId !== '') {
            return $cookieAnonymousId;
        }

        $sessionIdentifier = $this->resolveSessionIdentifier($request);

        if (is_string($sessionIdentifier) && $sessionIdentifier !== '') {
            return 'session:'.$sessionIdentifier;
        }

        $fingerprint = implode('|', [
            (string) ($request->ip() ?? 'unknown-ip'),
            trim((string) ($request->userAgent() ?? 'unknown-agent')),
        ]);

        return 'guest:'.substr(hash('sha256', $fingerprint), 0, 40);
    }

    private function resolvePath(?Request $request): ?string
    {
        if (! $request instanceof Request) {
            return null;
        }

        $path = trim($request->path(), '/');

        return $path === '' ? '/' : '/'.$path;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function normalizeProperties(string $eventName, ?Request $request, array $properties): array
    {
        $routeName = $request?->route()?->getName();

        $context = array_filter([
            'route_name' => is_string($routeName) && $routeName !== '' ? $routeName : null,
            'request_method' => $request?->getMethod(),
            ...$this->clientContext->properties($request),
        ], function (mixed $value): bool {
            if ($value === null || $value === '') {
                return false;
            }

            if (is_array($value)) {
                return $value !== [];
            }

            return true;
        });

        return [
            ...$this->schemaRegistry->normalize($eventName, $properties),
            ...$context,
        ];
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
