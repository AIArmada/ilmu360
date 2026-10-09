<?php

declare(strict_types=1);

namespace App\Services\ShareTracking;

use App\Enums\DawahShareSubjectType;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Series;
use App\Support\Models\SlugOrUuidResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class ShareTrackingUrlService
{
    public function __construct(
        private SlugOrUuidResolver $slugOrUuidResolver,
    ) {}

    /**
     * @return list<string>
     */
    public function supportedOrigins(): array
    {
        return ['web', 'iosapp', 'android', 'macapp'];
    }

    /**
     * @return list<string>
     */
    public function supportedProviders(): array
    {
        return ['whatsapp', 'telegram', 'threads', 'facebook', 'x', 'instagram', 'tiktok', 'email'];
    }

    /**
     * @return list<string>
     */
    public function trackedChannels(): array
    {
        return [
            ...$this->supportedProviders(),
            'copy_link',
            'native_share',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function channelUrls(string $url): array
    {
        return collect($this->trackedChannels())
            ->mapWithKeys(fn (string $channel): array => [
                $channel => $this->channelUrl($url, $channel),
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function platformLinks(string $url, string $shareText): array
    {
        return collect($this->supportedProviders())
            ->mapWithKeys(fn (string $provider): array => [
                $provider => $this->platformLink($provider, $this->appendShareProvider($url, $provider), $shareText),
            ])
            ->all();
    }

    public function platformLink(string $provider, string $url, string $shareText): string
    {
        $encodedUrl = urlencode($url);
        $encodedText = urlencode($shareText);
        $encodedBody = urlencode($shareText."\n".$url);

        return match ($provider) {
            'whatsapp' => "https://wa.me/?text={$encodedText}%20{$encodedUrl}",
            'telegram' => "https://t.me/share/url?url={$encodedUrl}&text={$encodedText}",
            'threads' => "https://www.threads.net/intent/post?text={$encodedText}%20{$encodedUrl}",
            'facebook' => "https://www.facebook.com/sharer/sharer.php?u={$encodedUrl}",
            'x' => "https://x.com/intent/tweet?text={$encodedText}&url={$encodedUrl}",
            'instagram' => 'https://www.instagram.com/',
            'tiktok' => 'https://www.tiktok.com/',
            'email' => "mailto:?subject={$encodedText}&body={$encodedBody}",
            default => $url,
        };
    }

    public function channelUrl(string $url, ?string $provider): string
    {
        return $this->appendShareProvider($url, $provider);
    }

    public function normalizeAbsoluteInternalUrl(string $url): string
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            $host = parse_url($url, PHP_URL_HOST);
            $allowedHost = parse_url($baseUrl, PHP_URL_HOST);
            $currentHost = request()->getHost();

            if ($host !== null && ! in_array($host, array_filter([$allowedHost, $currentHost]), true)) {
                throw new \InvalidArgumentException('Only internal ilmu360° URLs can be shared.');
            }

            return $url;
        }

        $path = str_starts_with($url, '/') ? $url : '/'.$url;

        return $baseUrl.$path;
    }

    /**
     * Classify a recipient navigation URL. Lenient: visited pages can be
     * anything, including private screens, and must never throw here.
     *
     * @return array{
     *     subject_type: string,
     *     subject_id: string|null,
     *     subject_key: string,
     *     destination_url: string,
     *     canonical_url: string,
     *     title_snapshot: string,
     *     metadata: array<string, mixed>
     * }
     */
    public function classifyUrl(string $url, ?string $fallbackTitle = null): array
    {
        return $this->classifyInternalUrl($url, $fallbackTitle);
    }

    /**
     * Classify a proposed share target. Strict: private, admin, auth and
     * secret-bearing URLs can never become tracked share destinations.
     *
     * @return array{
     *     subject_type: string,
     *     subject_id: string|null,
     *     subject_key: string,
     *     destination_url: string,
     *     canonical_url: string,
     *     title_snapshot: string,
     *     metadata: array<string, mixed>
     * }
     */
    public function classifyShareTarget(string $url, ?string $fallbackTitle = null): array
    {
        $this->guardPublicShareTarget($url);

        return $this->classifyInternalUrl($url, $fallbackTitle);
    }

    /**
     * @return array{
     *     subject_type: string,
     *     subject_id: string|null,
     *     subject_key: string,
     *     destination_url: string,
     *     canonical_url: string,
     *     title_snapshot: string,
     *     metadata: array<string, mixed>
     * }
     */
    private function classifyInternalUrl(string $url, ?string $fallbackTitle = null): array
    {
        $absoluteUrl = $this->normalizeAbsoluteInternalUrl($url);
        $parsed = parse_url($absoluteUrl) ?: [];
        $path = (string) ($parsed['path'] ?? '/');
        $query = $this->normalizeQueryFromUrl($absoluteUrl);
        $routeMatch = $this->matchInternalRoute($path, $query);

        if ($routeMatch !== null) {
            return $this->classifyMatchedRoute($routeMatch['name'], $routeMatch['parameters'], $query, $fallbackTitle);
        }

        if (preg_match('#^/events/([^/]+)$#', $path, $matches) === 1 || preg_match('#^/majlis/([^/]+)$#', $path, $matches) === 1) {
            $event = Event::query()->where('slug', $matches[1])->first();

            if ($event instanceof Event) {
                return $this->subjectResult(
                    DawahShareSubjectType::Event,
                    $event->id,
                    'event:'.$event->id,
                    route('events.show', $event),
                    route('events.show', $event),
                    $event->title,
                    ['slug' => $event->slug],
                );
            }
        }

        return $this->subjectResult(
            DawahShareSubjectType::Page,
            null,
            'page:'.trim($path, '/'),
            $absoluteUrl,
            $this->buildAbsoluteUrl($path, $query),
            $fallbackTitle ?: config('app.name'),
            [
                'path' => $path,
                'query' => $query,
            ],
        );
    }

    /**
     * @return array{subject_type: string, subject_id: string|null, subject_key: string}
     */
    public function classifySubjectModel(Model $subject): array
    {
        return match (true) {
            $subject instanceof Event => [
                'subject_type' => DawahShareSubjectType::Event->value,
                'subject_id' => $subject->id,
                'subject_key' => 'event:'.$subject->id,
            ],
            $subject instanceof Institution => [
                'subject_type' => DawahShareSubjectType::Institution->value,
                'subject_id' => $subject->id,
                'subject_key' => 'institution:'.$subject->id,
            ],
            $subject instanceof Person => [
                'subject_type' => DawahShareSubjectType::Person->value,
                'subject_id' => $subject->id,
                'subject_key' => 'person:'.$subject->id,
            ],
            $subject instanceof Series => [
                'subject_type' => DawahShareSubjectType::Series->value,
                'subject_id' => $subject->id,
                'subject_key' => 'series:'.$subject->id,
            ],
            $subject instanceof Reference => [
                'subject_type' => DawahShareSubjectType::Reference->value,
                'subject_id' => $subject->id,
                'subject_key' => 'reference:'.$subject->id,
            ],
            default => [
                'subject_type' => DawahShareSubjectType::Page->value,
                'subject_id' => null,
                'subject_key' => 'page:model:'.$subject->getMorphClass().':'.$subject->getKey(),
            ],
        };
    }

    /**
     * Canonical minimal persisted identity for a recipient-visited URL.
     *
     * Stored attributions and visits keep the route path only. No recipient
     * query survives here: not tracking parameters, not campaign markers,
     * and never private trails (emails, tokens, credentials, q/search
     * terms). The deliberately shared destination URL, including the
     * sender's known public criteria, lives on the sender-owned link row
     * instead and is never rebuilt from recipient navigation.
     */
    public function minimalPersistedUrl(string $url): string
    {
        $parsed = parse_url($this->normalizeAbsoluteInternalUrl($url)) ?: [];
        $path = (string) ($parsed['path'] ?? '/');

        return $this->buildAbsoluteUrl($path, []);
    }

    public function isPrivateNavigationPath(string $path): bool
    {
        $normalizedPath = Str::lower('/'.ltrim($path, '/'));

        foreach ($this->privateSharePathPrefixes() as $prefix) {
            if ($normalizedPath === $prefix || str_starts_with($normalizedPath, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function publicTrackableRouteNames(): array
    {
        return [
            'home',
            'about',
            'events.index',
            'events.show',
            'events.calendar',
            'events.occurrence',
            'events.occurrence.calendar',
            'events.session',
            'events.session.calendar',
            'institutions.index',
            'institutions.show',
            'persons.index',
            'persons.show',
            'venues.index',
            'venues.show',
            'series.show',
            'references.index',
            'references.show',
            'submit-event.landing',
            'submit-event.create',
        ];
    }

    public function isPublicTrackableNavigation(Request $request): bool
    {
        if ($this->isPrivateNavigationPath('/'.ltrim($request->path(), '/'))) {
            return false;
        }

        $routeName = $request->route()?->getName();

        if (! is_string($routeName) || $routeName === '') {
            return false;
        }

        return in_array($routeName, $this->publicTrackableRouteNames(), true);
    }

    public function isPublicShareTarget(string $url): bool
    {
        try {
            $absoluteUrl = $this->normalizeAbsoluteInternalUrl($url);
        } catch (\InvalidArgumentException) {
            return false;
        }

        $parsed = parse_url($absoluteUrl) ?: [];
        $path = (string) ($parsed['path'] ?? '/');
        $query = $this->normalizeQueryFromUrl($absoluteUrl);
        $routeMatch = $this->matchInternalRoute($path, $query);

        if ($routeMatch === null) {
            return false;
        }

        return in_array($routeMatch['name'], $this->publicTrackableRouteNames(), true);
    }

    public function isBotRequest(Request $request): bool
    {
        $userAgent = Str::lower((string) $request->userAgent());

        if ($userAgent === '') {
            return false;
        }

        return array_any((array) config('dawah-share.bot_user_agents', []), fn ($needle) => str_contains($userAgent, Str::lower((string) $needle)));
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    public function appendQueryParameters(string $url, array $params): string
    {
        $parsed = parse_url($url) ?: [];
        $query = [];

        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query);
        }

        $query = array_merge($query, $params);
        $base = strtok($url, '?') ?: $url;

        return $base.'?'.http_build_query($query);
    }

    private function guardPublicShareTarget(string $url): void
    {
        $absoluteUrl = $this->normalizeAbsoluteInternalUrl($url);
        $path = Str::lower((string) (parse_url($absoluteUrl, PHP_URL_PATH) ?? '/'));

        foreach ($this->privateSharePathPrefixes() as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                throw new \InvalidArgumentException('Only public pages can be shared.');
            }
        }

        if (! $this->isPublicShareTarget($url)) {
            throw new \InvalidArgumentException('Only known public pages can be shared.');
        }

        parse_str((string) parse_url($absoluteUrl, PHP_URL_QUERY), $query);

        foreach (array_keys($query) as $key) {
            if (is_string($key) && in_array(Str::lower($key), $this->secretShareQueryKeys(), true)) {
                throw new \InvalidArgumentException('Links carrying credentials or private tokens cannot be shared.');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function privateSharePathPrefixes(): array
    {
        return [
            '/filament',
            '/livewire',
            '/api',
            '/admin',
            '/dashboard',
            '/account',
            '/tetapan-akaun',
            '/login',
            '/register',
            '/password',
            '/email',
            '/sanctum',
            '/oauth',
            '/_debugbar',
        ];
    }

    /**
     * @return list<string>
     */
    private function secretShareQueryKeys(): array
    {
        return [
            'token',
            'signature',
            'secret',
            'password',
            'access_token',
            'refresh_token',
            'api_key',
            'otp',
            // The canonical private claim-capability body input must never
            // arrive as a share-target query key: URLs carrying it cannot
            // become tracked destinations.
            'guest_claim_capability',
        ];
    }

    /**
     * Explicit bounded safe campaign ids/slugs from an incoming request.
     *
     * Allowlisted utm keys survive only when the value is already a short
     * safe slug. Free-form values, secrets smuggled into campaign keys and
     * overlong values are dropped outright: truncating an arbitrary secret
     * would still store a secret prefix. Shared by the attribution writer
     * and the short-link adapter so both forward the identical canonical
     * campaign set.
     *
     * @return array<string, string>
     */
    public function sanitizedCampaignParams(Request $request): array
    {
        $allowedKeys = array_filter((array) config('dawah-share.campaign_metadata_keys', []));
        $metadata = [];

        foreach ($allowedKeys as $key) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $value = $request->query($key);

            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($value === '' || strlen($value) > 120) {
                continue;
            }

            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $value) !== 1) {
                continue;
            }

            $metadata[$key] = $value;
        }

        return $metadata;
    }

    public function normalizeProvider(?string $provider): ?string
    {
        if (! is_string($provider) || $provider === '') {
            return null;
        }

        return in_array($provider, $this->trackedChannels(), true) ? $provider : null;
    }

    public function normalizeOrigin(?string $origin): ?string
    {
        if (! is_string($origin)) {
            return null;
        }

        $normalizedOrigin = Str::lower(trim($origin));

        if ($normalizedOrigin === '') {
            return null;
        }

        return $normalizedOrigin;
    }

    private function appendShareProvider(string $url, ?string $provider): string
    {
        $provider = $this->normalizeProvider($provider);

        if (! is_string($provider)) {
            return $url;
        }

        return $this->appendQueryParameters($url, [
            (string) config('dawah-share.provider_query_parameter', 'channel') => $provider,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeQueryFromUrl(string $url): array
    {
        parse_str((string) parse_url($this->normalizeAbsoluteInternalUrl($url), PHP_URL_QUERY), $query);

        $query = Arr::where($query, fn (mixed $value): bool => $this->filledQueryValue($value));
        unset($query[(string) config('dawah-share.query_parameter', 'share')]);
        unset($query[(string) config('dawah-share.provider_query_parameter', 'channel')]);

        return $this->sortQueryRecursively($query);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function sortQueryRecursively(array $query): array
    {
        foreach ($query as $key => $value) {
            if (is_array($value)) {
                $query[$key] = $this->sortNestedArray($value);
            }
        }

        ksort($query);

        return $query;
    }

    /**
     * @param  array<int|string, mixed>  $value
     * @return array<int|string, mixed>
     */
    private function sortNestedArray(array $value): array
    {
        if (array_is_list($value)) {
            sort($value);

            return $value;
        }

        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $value[$key] = $this->sortNestedArray($nested);
            }
        }

        ksort($value);

        return $value;
    }

    private function filledQueryValue(mixed $value): bool
    {
        if (is_array($value)) {
            return Arr::where($value, fn (mixed $nested): bool => $this->filledQueryValue($nested)) !== [];
        }

        return filled($value);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{name: string, parameters: array<string, mixed>}|null
     */
    private function matchInternalRoute(string $path, array $query): ?array
    {
        try {
            $route = Route::getRoutes()->match(Request::create($path.'?'.http_build_query($query), 'GET'));
        } catch (NotFoundHttpException) {
            return null;
        }

        $name = $route->getName();

        if (! is_string($name) || $name === '') {
            return null;
        }

        return [
            'name' => $name,
            'parameters' => $route->parameters(),
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $query
     * @return array{
     *     subject_type: string,
     *     subject_id: string|null,
     *     subject_key: string,
     *     destination_url: string,
     *     canonical_url: string,
     *     title_snapshot: string,
     *     metadata: array<string, mixed>
     * }
     */
    private function classifyMatchedRoute(string $routeName, array $parameters, array $query, ?string $fallbackTitle): array
    {
        return match ($routeName) {
            'events.show' => $this->eventTarget((string) ($parameters['event'] ?? '')),
            'institutions.show' => $this->institutionTarget((string) ($parameters['institution'] ?? '')),
            'persons.show' => $this->personTarget((string) ($parameters['person'] ?? '')),
            'series.show' => $this->seriesTarget((string) ($parameters['series'] ?? '')),
            'references.show' => $this->referenceTarget((string) ($parameters['reference'] ?? '')),
            'events.index' => $this->searchOrPageTarget($query, $fallbackTitle),
            default => $this->subjectResult(
                DawahShareSubjectType::Page,
                null,
                'page:'.$routeName,
                $this->buildAbsoluteUrl((string) parse_url(route($routeName, $parameters, false), PHP_URL_PATH), $query),
                $this->buildAbsoluteUrl((string) parse_url(route($routeName, $parameters, false), PHP_URL_PATH), $query),
                $fallbackTitle ?: Str::headline(str_replace('.', ' ', $routeName)),
                [
                    'route_name' => $routeName,
                    'parameters' => $parameters,
                    'query' => $query,
                ],
            ),
        };
    }

    /**
     * @return array{
     *     subject_type: string,
     *     subject_id: string|null,
     *     subject_key: string,
     *     destination_url: string,
     *     canonical_url: string,
     *     title_snapshot: string,
     *     metadata: array<string, mixed>
     * }
     */
    private function eventTarget(string $slug): array
    {
        $event = Event::query()->where('slug', $slug)->first();

        if (! $event instanceof Event) {
            return $this->subjectResult(
                DawahShareSubjectType::Page,
                null,
                'page:majlis:'.$slug,
                $this->buildAbsoluteUrl('/majlis/'.$slug, []),
                $this->buildAbsoluteUrl('/majlis/'.$slug, []),
                config('app.name'),
                ['slug' => $slug],
            );
        }

        return $this->subjectResult(
            DawahShareSubjectType::Event,
            $event->id,
            'event:'.$event->id,
            route('events.show', $event),
            route('events.show', $event),
            $event->title,
            ['slug' => $event->slug],
        );
    }

    /**
     * @return array{
     *     subject_type: string,
     *     subject_id: string|null,
     *     subject_key: string,
     *     destination_url: string,
     *     canonical_url: string,
     *     title_snapshot: string,
     *     metadata: array<string, mixed>
     * }
     */
    private function institutionTarget(string $slug): array
    {
        $institution = Institution::query()->where('slug', $slug)->first();

        if (! $institution instanceof Institution) {
            return $this->subjectResult(
                DawahShareSubjectType::Page,
                null,
                'page:institusi:'.$slug,
                $this->buildAbsoluteUrl('/institusi/'.$slug, []),
                $this->buildAbsoluteUrl('/institusi/'.$slug, []),
                config('app.name'),
                ['slug' => $slug],
            );
        }

        return $this->subjectResult(
            DawahShareSubjectType::Institution,
            $institution->id,
            'institution:'.$institution->id,
            route('institutions.show', $institution),
            route('institutions.show', $institution),
            $institution->name,
            ['slug' => $institution->slug],
        );
    }

    /**
     * @return array{
     *     subject_type: string,
     *     subject_id: string|null,
     *     subject_key: string,
     *     destination_url: string,
     *     canonical_url: string,
     *     title_snapshot: string,
     *     metadata: array<string, mixed>
     * }
     */
    private function personTarget(string $slug): array
    {
        $person = Person::query()->where('slug', $slug)->first();

        if (! $person instanceof Person) {
            return $this->subjectResult(
                DawahShareSubjectType::Page,
                null,
                'page:penceramah:'.$slug,
                $this->buildAbsoluteUrl('/penceramah/'.$slug, []),
                $this->buildAbsoluteUrl('/penceramah/'.$slug, []),
                config('app.name'),
                ['slug' => $slug],
            );
        }

        return $this->subjectResult(
            DawahShareSubjectType::Person,
            $person->id,
            'person:'.$person->id,
            route('persons.show', $person),
            route('persons.show', $person),
            $person->formatted_name,
            ['slug' => $person->slug],
        );
    }

    /**
     * @return array{
     *     subject_type: string,
     *     subject_id: string|null,
     *     subject_key: string,
     *     destination_url: string,
     *     canonical_url: string,
     *     title_snapshot: string,
     *     metadata: array<string, mixed>
     * }
     */
    private function seriesTarget(string $slug): array
    {
        $series = Series::query()->where('slug', $slug)->first();

        if (! $series instanceof Series) {
            return $this->subjectResult(
                DawahShareSubjectType::Page,
                null,
                'page:series:'.$slug,
                $this->buildAbsoluteUrl('/siri/'.$slug, []),
                $this->buildAbsoluteUrl('/siri/'.$slug, []),
                config('app.name'),
                ['slug' => $slug],
            );
        }

        return $this->subjectResult(
            DawahShareSubjectType::Series,
            $series->id,
            'series:'.$series->id,
            route('series.show', $series),
            route('series.show', $series),
            $series->title,
            ['slug' => $series->slug],
        );
    }

    /**
     * @return array{
     *     subject_type: string,
     *     subject_id: string|null,
     *     subject_key: string,
     *     destination_url: string,
     *     canonical_url: string,
     *     title_snapshot: string,
     *     metadata: array<string, mixed>
     * }
     */
    private function referenceTarget(string $referenceIdentifier): array
    {
        $reference = $this->slugOrUuidResolver->first(
            Reference::query()->withoutOwnerScope()->active(),
            'references.slug',
            $referenceIdentifier,
        );

        if (! $reference instanceof Reference) {
            return $this->subjectResult(
                DawahShareSubjectType::Page,
                null,
                'page:reference:'.$referenceIdentifier,
                $this->buildAbsoluteUrl('/rujukan/'.$referenceIdentifier, []),
                $this->buildAbsoluteUrl('/rujukan/'.$referenceIdentifier, []),
                config('app.name'),
                ['reference' => $referenceIdentifier],
            );
        }

        return $this->subjectResult(
            DawahShareSubjectType::Reference,
            $reference->id,
            'reference:'.$reference->id,
            route('references.show', $reference),
            route('references.show', $reference),
            $reference->displayTitle(),
            ['reference' => $reference->slug],
        );
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{
     *     subject_type: string,
     *     subject_id: string|null,
     *     subject_key: string,
     *     destination_url: string,
     *     canonical_url: string,
     *     title_snapshot: string,
     *     metadata: array<string, mixed>
     * }
     */
    private function searchOrPageTarget(array $query, ?string $fallbackTitle): array
    {
        $canonicalUrl = $this->buildAbsoluteUrl('/majlis', $query);

        if ($query === []) {
            return $this->subjectResult(
                DawahShareSubjectType::Page,
                null,
                'page:events.index',
                $canonicalUrl,
                $canonicalUrl,
                $fallbackTitle ?: __('Events'),
                ['route_name' => 'events.index'],
            );
        }

        return $this->subjectResult(
            DawahShareSubjectType::Search,
            null,
            'search:'.$canonicalUrl,
            $canonicalUrl,
            $canonicalUrl,
            $fallbackTitle ?: __('Search Results'),
            ['query' => $query],
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{
     *     subject_type: string,
     *     subject_id: string|null,
     *     subject_key: string,
     *     destination_url: string,
     *     canonical_url: string,
     *     title_snapshot: string,
     *     metadata: array<string, mixed>
     * }
     */
    private function subjectResult(
        DawahShareSubjectType $type,
        ?string $subjectId,
        string $subjectKey,
        string $destinationUrl,
        string $canonicalUrl,
        string $titleSnapshot,
        array $metadata,
    ): array {
        return [
            'subject_type' => $type->value,
            'subject_id' => $subjectId,
            'subject_key' => $subjectKey,
            'destination_url' => $destinationUrl,
            'canonical_url' => $canonicalUrl,
            'title_snapshot' => $titleSnapshot,
            'metadata' => $metadata,
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function buildAbsoluteUrl(string $path, array $query): string
    {
        $base = rtrim((string) config('app.url'), '/').(str_starts_with($path, '/') ? $path : '/'.$path);

        if ($query === []) {
            return $base;
        }

        return $base.'?'.http_build_query($query);
    }
}
