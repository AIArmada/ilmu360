<?php

declare(strict_types=1);

namespace App\Services\Signals;

use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;
use App\Models\Event;
use App\Models\NotificationMessage;
use App\Models\Report;
use App\Models\User;
use App\Support\Signals\ProductSignalsClientContext;
use Illuminate\Http\Request;
use Throwable;

final readonly class ProductSignalsService
{
    public function __construct(
        private SignalEventRecorder $signalEventRecorder,
        private SignalsTracker $signalsTracker,
        private ProductSignalsClientContext $clientContext,
    ) {}

    public function recordLogin(User $user, Request $request, string $method, bool $createdAccount = false): ?SignalEvent
    {
        return $this->record(
            request: $request,
            eventName: 'auth.login',
            eventCategory: 'auth',
            user: $user,
            properties: [
                'method' => $method,
                'created_account' => $createdAccount,
            ],
        );
    }

    public function recordSignup(User $user, ?Request $request = null): ?SignalEvent
    {
        return $this->record(
            request: $request,
            eventName: 'auth.signup.completed',
            eventCategory: 'auth',
            user: $user,
            properties: [
                'has_email' => filled($user->email),
                'has_phone' => filled($user->phone),
            ],
        );
    }

    public function recordPasswordResetCompleted(User $user, ?Request $request = null): ?SignalEvent
    {
        return $this->record(
            request: $request,
            eventName: 'auth.password_reset.completed',
            eventCategory: 'auth',
            user: $user,
        );
    }

    public function recordEmailVerified(User $user, ?Request $request = null): ?SignalEvent
    {
        return $this->record(
            request: $request,
            eventName: 'auth.email_verified',
            eventCategory: 'auth',
            user: $user,
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
            request: $request,
            eventName: 'moderation.event.'.$action,
            eventCategory: 'moderation',
            user: $moderator,
            properties: [
                'event_id' => (string) $event->getKey(),
                'moderation_action' => $action,
                'previous_status' => $previousStatus,
                'current_status' => (string) $event->status,
                'moderator_id' => $moderator?->getKey(),
                'reason_code' => $reasonCode,
                'has_note' => filled($note),
            ],
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
            request: $request,
            eventName: 'admin.event_cover.generated',
            eventCategory: 'admin',
            user: $user,
            properties: [
                'event_id' => (string) $event->getKey(),
                'event_status' => (string) $event->status,
                ...$properties,
            ],
        );
    }

    public function recordReportSubmitted(Report $report, ?Request $request = null): ?SignalEvent
    {
        $user = $request?->user();

        return $this->record(
            request: $request,
            eventName: 'report.submitted',
            eventCategory: 'moderation',
            user: $user instanceof User ? $user : null,
            properties: [
                'report_id' => (string) $report->getKey(),
                'entity_type' => (string) $report->entity_type,
                'entity_id' => (string) $report->entity_id,
                'category' => (string) $report->category,
                'status' => (string) $report->status,
            ],
            anonymousId: (string) $report->reporter_fingerprint,
        );
    }

    public function recordNotificationRead(NotificationMessage $message, User $user, ?Request $request = null): ?SignalEvent
    {
        return $this->record(
            request: $request,
            eventName: 'notification.read',
            eventCategory: 'notification',
            user: $user,
            properties: [
                'notification_id' => (string) $message->getKey(),
                'family' => $this->enumValue($message->family),
                'trigger' => $this->enumValue($message->trigger),
                'action_url' => $message->action_url,
            ],
        );
    }

    public function recordNotificationsReadAll(User $user, int $updatedCount, ?Request $request = null): ?SignalEvent
    {
        if ($updatedCount < 1) {
            return null;
        }

        return $this->record(
            request: $request,
            eventName: 'notification.read_all',
            eventCategory: 'notification',
            user: $user,
            properties: [
                'updated_count' => $updatedCount,
            ],
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
            request: $request,
            eventName: $hasQuery ? 'search.executed' : 'listing.filtered',
            eventCategory: $hasQuery ? 'search' : 'discovery',
            user: $user,
            properties: [
                'interaction_type' => $hasQuery ? 'search' : 'filter',
                'surface' => $surface,
                'query' => $normalizedQuery,
                'filter_keys' => array_values(array_map(static fn (string $key): string => $key, array_keys($normalizedFilters))),
                'filters' => $normalizedFilters,
                'result_count' => $resultCount,
                'saved_search_id' => $savedSearchId,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function record(
        ?Request $request,
        string $eventName,
        string $eventCategory,
        ?User $user = null,
        array $properties = [],
        ?string $anonymousId = null,
    ): ?SignalEvent {
        try {
            $trackedProperty = $this->signalsTracker->defaultTrackedProperty();

            if (! $trackedProperty instanceof TrackedProperty) {
                return null;
            }

            $payload = [
                'event_name' => $eventName,
                'event_category' => $eventCategory,
                'external_id' => $user?->getKey(),
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
                'properties' => $this->normalizeProperties($request, $properties),
            ];

            return $this->signalEventRecorder->ingest($trackedProperty, $payload);
        } catch (Throwable $exception) {
            report($exception);
            logger()->warning('Signals product telemetry skipped after ingestion failure.', [
                'event_name' => $eventName,
                'event_category' => $eventCategory,
                'route_name' => $request?->route()?->getName(),
                'user_id' => $user?->getKey(),
            ]);

            return null;
        }
    }

    private function resolveSessionIdentifier(?Request $request): ?string
    {
        if (! $request instanceof Request) {
            return null;
        }

        $cookieIdentifier = trim((string) $request->cookies->get((string) config('product-signals.identity.session_cookie', 'mi_signals_session_id')));

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

        $cookieAnonymousId = trim((string) $request->cookies->get((string) config('product-signals.identity.anonymous_cookie', 'mi_signals_anonymous_id')));

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
    private function normalizeProperties(?Request $request, array $properties): array
    {
        $routeName = $request?->route()?->getName();

        return array_filter([
            'route_name' => is_string($routeName) && $routeName !== '' ? $routeName : null,
            'request_method' => $request?->getMethod(),
            ...$properties,
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
