<?php

declare(strict_types=1);

namespace App\Platform\Telemetry;

use AIArmada\Signals\Contracts\SignalEventIngestor;
use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;
use App\Shared\Signals\SignalRecorder;
use Illuminate\Http\Request;
use Throwable;

final readonly class ProductSignalRecorder implements SignalRecorder
{
    public function __construct(
        private SignalEventIngestor $ingestSignalEvent,
        private ProductSignalsClientContext $clientContext,
        private ProductSignalSchemaRegistry $schemaRegistry,
    ) {}

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
}
