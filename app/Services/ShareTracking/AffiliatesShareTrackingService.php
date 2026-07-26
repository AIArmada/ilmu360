<?php

declare(strict_types=1);

namespace App\Services\ShareTracking;

use AIArmada\Affiliates\Actions\Affiliates\CreateTrackingLink;
use AIArmada\Affiliates\Actions\Conversions\RecordAffiliateOutcome;
use AIArmada\Affiliates\Data\AffiliateConversionData;
use AIArmada\Affiliates\Enums\CommissionType;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\Affiliates\States\Active;
use AIArmada\Affiliates\States\ApprovedConversion;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerScope;
use App\Data\ShareTracking\ShareTrackingAttributionData;
use App\Data\ShareTracking\ShareTrackingLinkData;
use App\Data\ShareTracking\ShareTrackingOutcomeData;
use App\Enums\DawahShareOutcomeType;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Series;
use App\Models\User;
use App\Services\Signals\AffiliateSignalsBridge;
use Carbon\CarbonInterface;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

final readonly class AffiliatesShareTrackingService
{
    private const int SHARE_TOKEN_LENGTH = 16;

    public function __construct(
        private ShareTrackingUrlService $shareTrackingUrlService,
        private AffiliateSignalsBridge $affiliateSignalsBridge,
        private CreateTrackingLink $createTrackingLink,
        private RecordAffiliateOutcome $recordAffiliateOutcome,
    ) {}

    /**
     * @return list<string>
     */
    public function supportedProviders(): array
    {
        return $this->shareTrackingUrlService->supportedProviders();
    }

    /**
     * @return list<string>
     */
    public function supportedChannels(): array
    {
        return $this->shareTrackingUrlService->trackedChannels();
    }

    /**
     * @return list<string>
     */
    public function supportedOrigins(): array
    {
        return $this->shareTrackingUrlService->supportedOrigins();
    }

    /**
     * @return array<string, mixed>
     */
    public function sharePayload(?User $user, string $url, string $shareText, ?string $fallbackTitle = null, ?string $origin = null, ?Request $request = null): array
    {
        $resolvedOrigin = $this->resolveShareOrigin($origin);

        if ($user instanceof User) {
            return OwnerContext::withOwner($user, fn (): array => $this->buildTrackedSharePayload(
                $this->createOrReuseAffiliateLinkForAffiliate(
                    $this->ensureAffiliateForUser($user),
                    $url,
                    $fallbackTitle,
                    $resolvedOrigin,
                ),
                $shareText,
                $fallbackTitle,
            ));
        }

        if ($request instanceof Request) {
            return OwnerContext::withOwner(null, fn (): array => $this->buildTrackedSharePayload(
                $this->createOrReuseGuestAffiliateLink(
                    $request,
                    $url,
                    $fallbackTitle,
                    $resolvedOrigin,
                ),
                $shareText,
                $fallbackTitle,
            ));
        }

        $shareUrl = $this->shareTrackingUrlService->normalizeAbsoluteInternalUrl($url);
        $channelUrls = $this->shareTrackingUrlService->channelUrls($shareUrl);

        return [
            'url' => $shareUrl,
            'platform_links' => $this->shareTrackingUrlService->platformLinks($shareUrl, $shareText),
            'channel_urls' => $channelUrls,
            'copy_link' => [
                'url' => $channelUrls['copy_link'] ?? $shareUrl,
            ],
            'native_share' => $this->nativeSharePayload(
                $channelUrls['native_share'] ?? $shareUrl,
                $shareText,
                $fallbackTitle,
            ),
            'origin' => $resolvedOrigin,
            'supported_channels' => $this->supportedChannels(),
            'supported_origins' => $this->supportedOrigins(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function redirectLinks(string $url, string $shareText, ?string $fallbackTitle = null): array
    {
        $normalizedUrl = $this->shareTrackingUrlService->normalizeAbsoluteInternalUrl($url);
        $query = [
            'url' => $normalizedUrl,
            'text' => $shareText,
        ];

        if (filled($fallbackTitle)) {
            $query['title'] = $fallbackTitle;
        }

        return collect($this->supportedProviders())
            ->mapWithKeys(fn (string $provider): array => [
                $provider => route('dawah-share.redirect', ['provider' => $provider] + $query),
            ])
            ->all();
    }

    public function redirectUrl(
        string $provider,
        ?User $user,
        string $url,
        string $shareText,
        ?string $fallbackTitle = null,
        ?string $origin = null,
        ?Request $request = null,
    ): string {
        if ($user instanceof User) {
            return OwnerContext::withOwner($user, function () use ($provider, $user, $url, $shareText, $fallbackTitle, $origin, $request): string {
                $link = $this->createOrReuseAffiliateLinkForAffiliate(
                    $this->ensureAffiliateForUser($user),
                    $url,
                    $fallbackTitle,
                    $origin,
                );
                $this->recordOutboundShare($link, $user, $provider, $request);

                return $this->shareTrackingUrlService->platformLinks($this->sharedUrlForLink($link), $shareText)[$provider] ?? $url;
            });
        }

        if ($request instanceof Request) {
            return OwnerContext::withOwner(null, function () use ($provider, $url, $shareText, $fallbackTitle, $origin, $request): string {
                $link = $this->createOrReuseGuestAffiliateLink($request, $url, $fallbackTitle, $origin);
                $this->recordOutboundShare($link, null, $provider, $request);

                return $this->shareTrackingUrlService->platformLinks($this->sharedUrlForLink($link), $shareText)[$provider] ?? $url;
            });
        }

        $shareUrl = $this->shareTrackingUrlService->normalizeAbsoluteInternalUrl($url);

        return $this->shareTrackingUrlService->platformLinks($shareUrl, $shareText)[$provider] ?? $shareUrl;
    }

    public function attributedUrl(User $user, string $url, ?string $fallbackTitle = null, ?string $origin = null): string
    {
        return OwnerContext::withOwner($user, fn (): string => $this->sharedUrlForLink($this->createOrReuseAffiliateLink($user, $url, $fallbackTitle, $origin)));
    }

    public function recordShareAction(
        string $provider,
        ?User $user,
        string $trackingToken,
        ?Request $request = null,
    ): void {
        $link = $this->resolveLinkFromTrackingToken($trackingToken, $user);

        if (! $link instanceof AffiliateLink) {
            throw new \InvalidArgumentException('The share tracking token is invalid. Refresh the page and try sharing again.');
        }

        $owner = $user ?? $this->ownerForAffiliateLink($link);

        OwnerContext::withOwner($owner, fn () => $this->recordOutboundShare($link, $user, $provider, $request));
    }

    public function captureRequest(Request $request): ?string
    {
        $cookieState = $this->readCookieState($request);

        if ($this->shouldIgnoreRequest($request) || ! $request->isMethod('GET') || $request->expectsJson() || $request->ajax() || $this->isBotRequest($request)) {
            return $cookieState['encoded'] ?? null;
        }

        $parameter = (string) config('dawah-share.query_parameter', 'share');
        $shareToken = $request->query($parameter);
        $visitorKey = $cookieState['visitor_key'] ?? (string) Str::ulid();
        $shareProvider = $this->shareProviderFromRequest($request);

        if (is_string($shareToken) && $shareToken !== '') {
            $link = $this->resolveLinkFromShareToken($shareToken);

            if ($link instanceof AffiliateLink) {
                return OwnerContext::withOwner($this->ownerForAffiliateLink($link), function () use ($link, $request, $visitorKey, $shareProvider): string {
                    $attribution = $this->upsertLandingAttribution($link, $request, $visitorKey, $shareProvider);
                    $this->recordVisitTouchpoint($attribution, $request, 'landing', $this->cleanTrackedUrl($request->fullUrl()));

                    return $this->encodeCookieState($visitorKey, (string) $attribution->cookie_value);
                });
            }
        }

        $attribution = $this->resolveActiveAffiliateAttribution($request);

        if (! $attribution instanceof AffiliateAttribution) {
            return $cookieState['encoded'] ?? null;
        }

        return OwnerContext::withOwner($this->ownerForAffiliateAttribution($attribution), function () use ($attribution, $request, $visitorKey): string {
            $requestUser = $request->user();

            $attribution->forceFill([
                'last_seen_at' => now(),
                'expires_at' => $this->expiryTimestamp(),
                'user_id' => $requestUser instanceof User ? (string) $requestUser->getAuthIdentifier() : $attribution->user_id,
            ])->save();

            $cleanUrl = $this->cleanTrackedUrl($request->fullUrl());
            $kind = $cleanUrl === (string) $attribution->landing_url ? 'return' : 'navigated';

            if (! $this->recentDuplicateVisitExists($attribution, $cleanUrl)) {
                $this->recordVisitTouchpoint($attribution, $request, $kind, $cleanUrl);
            }

            return $this->encodeCookieState(
                (string) ($attribution->visitor_key ?? $visitorKey),
                (string) $attribution->cookie_value,
            );
        });
    }

    public function resolveActiveAttribution(?Request $request = null): ?ShareTrackingAttributionData
    {
        $attribution = $this->resolveActiveAffiliateAttribution($request);

        return $attribution instanceof AffiliateAttribution ? $this->mapAttribution($attribution) : null;
    }

    public function recordSignup(User $user, ?Request $request = null): ?ShareTrackingOutcomeData
    {
        return $this->recordOutcome(
            type: DawahShareOutcomeType::Signup,
            outcomeKey: 'signup:user:'.$user->id,
            actor: $user,
            request: $request,
            metadata: [
                'signed_up_user_id' => $user->id,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordOutcome(
        DawahShareOutcomeType $type,
        string $outcomeKey,
        ?Model $subject = null,
        ?User $actor = null,
        ?Request $request = null,
        array $metadata = [],
    ): ?ShareTrackingOutcomeData {
        $attribution = $this->resolveActiveAffiliateAttribution($request);

        if (! $attribution instanceof AffiliateAttribution) {
            return null;
        }

        return OwnerContext::withOwner($this->ownerForAffiliateAttribution($attribution), function () use ($attribution, $type, $outcomeKey, $subject, $metadata, $actor): ShareTrackingOutcomeData {
            $existing = AffiliateConversion::query()
                ->where('external_reference', $outcomeKey)
                ->first();

            if ($existing instanceof AffiliateConversion) {
                return $this->mapOutcome($existing);
            }

            $occurredAt = now();
            $linkId = $this->nullableString($attribution->affiliate_link_id) ?? '';
            $sharerUserId = $this->nullableString($attribution->sharer_user_id);
            $link = $linkId !== '' ? AffiliateLink::query()->find($linkId) : null;
            $subjectData = $this->resolveOutcomeSubjectData($subject, $attribution, $link);

            $conversionData = $this->recordAffiliateOutcome->handle(
                attribution: $attribution,
                conversionType: $type->value,
                externalReference: $outcomeKey,
                payload: [
                    'subject_type' => $subjectData['subject_type'],
                    'subject_key' => $subjectData['subject_key'],
                    'subject_id' => $subjectData['subject_id'],
                    'subject_instance' => 'default',
                    'subject_title_snapshot' => Str::limit($subjectData['title_snapshot'], 200, ''),
                    'commission_currency' => (string) config('affiliates.currency.default', 'MYR'),
                    'status' => ApprovedConversion::class,
                    'channel' => $attribution->channel,
                    'origin' => $attribution->origin,
                    'affiliate_link_id' => $attribution->affiliate_link_id,
                    'sharer_user_id' => $sharerUserId ?: null,
                    'actor_user_id' => $actor?->id,
                    'occurred_at' => $occurredAt,
                    'approved_at' => $occurredAt,
                    'dispatch_event' => false,
                    'metadata' => $metadata,
                ],
            );

            if (! $conversionData instanceof AffiliateConversionData) {
                return $this->mapOutcome(AffiliateConversion::query()
                    ->where('external_reference', $outcomeKey)
                    ->firstOrFail());
            }

            $conversion = AffiliateConversion::query()->findOrFail($conversionData->id);

            if ($linkId !== '') {
                AffiliateLink::query()->whereKey($linkId)->first()?->incrementConversions();
            }

            $this->dispatchAffiliateConversionRecorded($conversion);

            return $this->mapOutcome($conversion);
        });
    }

    public function createOrReuseLink(User $user, string $url, ?string $fallbackTitle = null, ?string $origin = null): ShareTrackingLinkData
    {
        return OwnerContext::withOwner($user, fn (): ShareTrackingLinkData => $this->mapLink($this->createOrReuseAffiliateLink($user, $url, $fallbackTitle, $origin)));
    }

    public function deleteUserTracking(User $user): void
    {
        OwnerContext::withOwner($user, function () use ($user): void {
            $affiliate = $this->findAffiliateForUser($user);

            if (! $affiliate instanceof Affiliate) {
                return;
            }

            $affiliate->links()->delete();
            $affiliate->conversions()->delete();
            $affiliate->attributions()->each(fn (AffiliateAttribution $attribution): bool => (bool) $attribution->delete());
            $affiliate->delete();
        });
    }

    public function findAffiliateForUser(User $user): ?Affiliate
    {
        return Affiliate::query()
            ->where('owner_type', $user->getMorphClass())
            ->where('owner_id', $user->getKey())
            ->first();
    }

    private function createOrReuseAffiliateLink(User $user, string $url, ?string $fallbackTitle = null, ?string $origin = null): AffiliateLink
    {
        return $this->createOrReuseAffiliateLinkForAffiliate(
            $this->ensureAffiliateForUser($user),
            $url,
            $fallbackTitle,
            $origin,
        );
    }

    private function createOrReuseAffiliateLinkForAffiliate(Affiliate $affiliate, string $url, ?string $fallbackTitle = null, ?string $origin = null): AffiliateLink
    {
        $target = $this->shareTrackingUrlService->classifyUrl($url, $fallbackTitle);
        $resolvedOrigin = $this->resolveShareOrigin($origin);

        $link = AffiliateLink::query()
            ->where('affiliate_id', $affiliate->id)
            ->where('tracking_url', $target['canonical_url'])
            ->where('origin', $resolvedOrigin)
            ->first();

        if (! $link instanceof AffiliateLink && $resolvedOrigin === 'web') {
            $link = AffiliateLink::query()
                ->where('affiliate_id', $affiliate->id)
                ->where('tracking_url', $target['canonical_url'])
                ->where(function ($query): void {
                    $query
                        ->whereNull('origin')
                        ->orWhere('origin', '');
                })
                ->first();
        }

        if ($link instanceof AffiliateLink) {
            $link->fill([
                'destination_url' => $target['destination_url'],
                'tracking_url' => $target['canonical_url'],
                'subject_type' => $target['subject_type'],
                'subject_key' => $target['subject_key'],
                'subject_id' => $target['subject_id'],
                'subject_instance' => 'default',
                'origin' => $resolvedOrigin,
                'subject_title_snapshot' => Str::limit($target['title_snapshot'], 200, ''),
                'subject_metadata' => $target['metadata'],
                'deactivated_at' => null,
            ]);

            if (! $this->isCurrentShareToken($link->custom_slug)) {
                $link->custom_slug = $this->generateShareToken();
            }

            if ($link->isDirty()) {
                $link->save();
            } else {
                $link->touch();
            }

            return $link;
        }

        return $this->createTrackingLink->handle(
            affiliate: $affiliate,
            destinationUrl: $target['destination_url'],
            attributes: [
                'tracking_url' => $target['canonical_url'],
                'custom_slug' => $this->generateShareToken(),
                'subject_type' => $target['subject_type'],
                'subject_key' => $target['subject_key'],
                'subject_id' => $target['subject_id'],
                'subject_instance' => 'default',
                'origin' => $resolvedOrigin,
                'subject_title_snapshot' => Str::limit($target['title_snapshot'], 200, ''),
                'subject_metadata' => $target['metadata'],
                'deactivated_at' => null,
            ],
        );
    }

    private function ensureAffiliateForUser(User $user): Affiliate
    {
        $affiliate = $this->findAffiliateForUser($user);

        if ($affiliate instanceof Affiliate) {
            $affiliate->fill([
                'name' => $user->name,
                'contact_email' => $user->email,
                'metadata' => $this->affiliateMetadata($user),
            ]);
            $affiliate->save();

            return $affiliate;
        }

        $affiliate = new Affiliate([
            'code' => $this->generateAffiliateCode($user),
            'name' => $user->name,
            'description' => 'ilmu360 share-tracking affiliate profile',
            'status' => Active::class,
            'commission_type' => CommissionType::Percentage,
            'commission_rate' => 0,
            'currency' => (string) config('affiliates.currency.default', 'MYR'),
            'contact_email' => $user->email,
            'metadata' => $this->affiliateMetadata($user),
            'activated_at' => now(),
        ]);

        $affiliate->assignOwner($user);
        $affiliate->save();

        return $affiliate;
    }

    private function createOrReuseGuestAffiliateLink(Request $request, string $url, ?string $fallbackTitle = null, ?string $origin = null): AffiliateLink
    {
        return $this->createOrReuseAffiliateLinkForAffiliate(
            $this->ensureAffiliateForGuest($request),
            $url,
            $fallbackTitle,
            $origin,
        );
    }

    private function ensureAffiliateForGuest(Request $request): Affiliate
    {
        $guestIdentifier = $this->resolveGuestIdentifier($request);
        $affiliate = $this->findAffiliateForGuest($guestIdentifier);

        if ($affiliate instanceof Affiliate) {
            $affiliate->fill([
                'name' => 'Anonymous Share Profile',
                'description' => 'ilmu360 anonymous share-tracking profile',
                'metadata' => $this->guestMetadata($guestIdentifier),
            ]);
            $affiliate->save();

            return $affiliate;
        }

        return OwnerContext::withOwner(null, fn (): Affiliate => Affiliate::query()->create([
            'code' => $this->generateGuestAffiliateCode($guestIdentifier),
            'name' => 'Anonymous Share Profile',
            'description' => 'ilmu360 anonymous share-tracking profile',
            'status' => Active::class,
            'commission_type' => CommissionType::Percentage,
            'commission_rate' => 0,
            'currency' => (string) config('affiliates.currency.default', 'MYR'),
            'metadata' => $this->guestMetadata($guestIdentifier),
            'activated_at' => now(),
        ]));
    }

    private function findAffiliateForGuest(string $guestIdentifier): ?Affiliate
    {
        return Affiliate::query()
            ->whereNull('owner_type')
            ->whereNull('owner_id')
            ->where('external_reference_type', 'majlis_guest')
            ->where('external_reference', $guestIdentifier)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function affiliateMetadata(User $user): array
    {
        return [
            'majlis_user_email' => $user->email,
            'majlis_user_name' => $user->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function guestMetadata(string $guestIdentifier): array
    {
        return [
            'majlis_guest_id' => $guestIdentifier,
        ];
    }

    private function generateAffiliateCode(User $user): string
    {
        $base = 'MI'.Str::upper(substr(str_replace('-', '', $user->id), 0, 8));
        $code = $base;
        $suffix = 1;

        while (Affiliate::query()->withoutGlobalScope(OwnerScope::class)->where('code', $code)->exists()) {
            $code = $base.$suffix;
            $suffix++;
        }

        return $code;
    }

    private function generateGuestAffiliateCode(string $guestIdentifier): string
    {
        $base = 'MG'.Str::upper(substr(hash('sha256', $guestIdentifier), 0, 8));
        $code = $base;
        $suffix = 1;

        while (Affiliate::query()->withoutGlobalScope(OwnerScope::class)->where('code', $code)->exists()) {
            if (Affiliate::query()->withoutGlobalScope(OwnerScope::class)->where('code', $code)->where('external_reference_type', 'majlis_guest')->where('external_reference', $guestIdentifier)->exists()) {
                return $code;
            }

            $code = $base.$suffix;
            $suffix++;
        }

        return $code;
    }

    private function resolveGuestIdentifier(Request $request): string
    {
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

    private function resolveSessionIdentifier(Request $request): ?string
    {
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

    private function sharedUrlForLink(AffiliateLink $link): string
    {
        $queryParameters = [
            (string) config('dawah-share.query_parameter', 'share') => (string) $link->custom_slug,
        ];

        $origin = $this->shareOriginForLink($link);

        if ($origin !== 'web') {
            $queryParameters['origin'] = $origin;
        }

        return $this->appendQueryParameters($link->destination_url, $queryParameters);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTrackedSharePayload(AffiliateLink $link, string $shareText, ?string $fallbackTitle = null): array
    {
        $shareUrl = $this->sharedUrlForLink($link);
        $channelUrls = $this->shareTrackingUrlService->channelUrls($shareUrl);

        return [
            'url' => $shareUrl,
            'platform_links' => $this->shareTrackingUrlService->platformLinks($shareUrl, $shareText),
            'channel_urls' => $channelUrls,
            'copy_link' => [
                'url' => $channelUrls['copy_link'] ?? $shareUrl,
            ],
            'native_share' => $this->nativeSharePayload(
                $channelUrls['native_share'] ?? $shareUrl,
                $shareText,
                $fallbackTitle,
            ),
            'origin' => $this->shareOriginForLink($link),
            'supported_channels' => $this->supportedChannels(),
            'supported_origins' => $this->supportedOrigins(),
            'tracking_token' => $this->trackingTokenForLink($link),
        ];
    }

    private function trackingTokenForLink(AffiliateLink $link): string
    {
        return (string) $link->custom_slug;
    }

    private function resolveLinkFromShareToken(string $shareToken): ?AffiliateLink
    {
        $normalizedToken = $this->normalizeShareToken($shareToken);

        if (! is_string($normalizedToken)) {
            return null;
        }

        return AffiliateLink::query()->withoutGlobalScope(OwnerScope::class)->where('custom_slug', $normalizedToken)->first();
    }

    private function resolveLinkFromTrackingToken(string $trackingToken, ?User $user = null): ?AffiliateLink
    {
        $normalizedToken = $this->normalizeShareToken($trackingToken);

        if (! is_string($normalizedToken)) {
            return null;
        }

        if (! $user instanceof User) {
            return AffiliateLink::query()->withoutGlobalScope(OwnerScope::class)->where('custom_slug', $normalizedToken)->first();
        }

        return AffiliateLink::query()
            ->where('custom_slug', $normalizedToken)
            ->whereHas('affiliate', fn ($query) => $query
                ->where('owner_type', $user->getMorphClass())
                ->where('owner_id', $user->getKey()))
            ->first();
    }

    private function generateShareToken(): string
    {
        do {
            $token = Str::lower(Str::random(self::SHARE_TOKEN_LENGTH));
        } while (AffiliateLink::query()->withoutGlobalScope(OwnerScope::class)->where('custom_slug', $token)->exists());

        return $token;
    }

    private function isCurrentShareToken(?string $token): bool
    {
        return is_string($this->normalizeShareToken($token));
    }

    private function normalizeShareToken(?string $token): ?string
    {
        if (! is_string($token)) {
            return null;
        }

        $normalizedToken = Str::lower(trim($token));

        if ($normalizedToken === '') {
            return null;
        }

        return preg_match('/^[a-z0-9]{'.self::SHARE_TOKEN_LENGTH.'}$/', $normalizedToken) === 1
            ? $normalizedToken
            : null;
    }

    private function upsertLandingAttribution(AffiliateLink $link, Request $request, string $visitorKey, ?string $shareProvider): AffiliateAttribution
    {
        $cookieState = $this->readCookieState($request);
        $cookieValue = $cookieState['attribution_cookie'] ?? (string) Str::ulid();
        $linkMetadata = $this->linkMetadata($link);
        $affiliate = $link->affiliate()->first();
        $requestUser = $request->user();

        $attribution = AffiliateAttribution::query()
            ->where('cookie_value', $cookieValue)
            ->first();

        $payload = [
            'affiliate_id' => $link->affiliate_id,
            'affiliate_code' => (string) $affiliate?->code,
            'subject_type' => $link->subject_type,
            'subject_key' => (string) ($linkMetadata['subject_key'] ?? $link->subject_key ?? $link->id),
            'subject_id' => $link->subject_id,
            'subject_instance' => 'default',
            'subject_title_snapshot' => $link->subject_title_snapshot,
            'cookie_value' => $cookieValue,
            'landing_url' => $this->cleanTrackedUrl($request->fullUrl()),
            'referrer_url' => $request->headers->get('referer'),
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
            'user_id' => $requestUser instanceof User ? (string) $requestUser->getAuthIdentifier() : null,
            'first_seen_at' => $attribution instanceof AffiliateAttribution ? $attribution->first_seen_at : now(),
            'last_seen_at' => now(),
            'last_cookie_seen_at' => now(),
            'expires_at' => $this->expiryTimestamp(),
            'affiliate_link_id' => $link->id,
            'attribution_type' => 'landing',
            'visitor_key' => $visitorKey,
            'channel' => $shareProvider,
            'origin' => $this->shareOriginForLink($link),
            'sharer_user_id' => $linkMetadata['sharer_user_id'] ?? null,
            'metadata' => Arr::except($request->query(), [
                (string) config('dawah-share.query_parameter', 'share'),
                (string) config('dawah-share.provider_query_parameter', 'channel'),
            ]) ?: null,
        ];

        if ($attribution instanceof AffiliateAttribution) {
            $attribution->fill($payload);
            $attribution->save();
            $this->dispatchAffiliateAttributed($attribution);

            return $attribution;
        }

        $attribution = AffiliateAttribution::query()->create($payload);
        $this->dispatchAffiliateAttributed($attribution);

        return $attribution;
    }

    private function resolveActiveAffiliateAttribution(?Request $request = null): ?AffiliateAttribution
    {
        $request ??= request();

        if (! $request instanceof Request) {
            return null;
        }

        $cookieState = $this->readCookieState($request);
        $cookieValue = $cookieState['attribution_cookie'] ?? null;

        if (! is_string($cookieValue) || $cookieValue === '') {
            return null;
        }

        return AffiliateAttribution::query()->withoutGlobalScope(OwnerScope::class)
            ->where('cookie_value', $cookieValue)
            ->where('attribution_type', 'landing')
            ->active()
            ->latest('last_cookie_seen_at')
            ->first();
    }

    private function recordVisitTouchpoint(AffiliateAttribution $attribution, Request $request, string $kind, string $cleanUrl): AffiliateTouchpoint
    {
        $subject = $this->shareTrackingUrlService->classifyUrl($cleanUrl, (string) ($attribution->subject_title_snapshot ?: config('app.name')));

        return AffiliateTouchpoint::query()->create([
            'affiliate_attribution_id' => $attribution->id,
            'affiliate_id' => $attribution->affiliate_id,
            'affiliate_code' => $attribution->affiliate_code,
            'source' => $attribution->source,
            'medium' => $attribution->medium,
            'campaign' => $attribution->campaign,
            'term' => $attribution->term,
            'content' => $attribution->content,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'touchpoint_type' => 'visit',
            'interaction_type' => $kind,
            'affiliate_link_id' => $attribution->affiliate_link_id,
            'visitor_key' => $attribution->visitor_key,
            'channel' => $attribution->channel,
            'origin' => $attribution->origin,
            'url' => $cleanUrl,
            'referrer_url' => $request->headers->get('referer'),
            'subject_type' => $subject['subject_type'],
            'subject_key' => $subject['subject_key'],
            'subject_id' => $subject['subject_id'],
            'touched_at' => now(),
        ]);
    }

    private function recentDuplicateVisitExists(AffiliateAttribution $attribution, string $cleanUrl): bool
    {
        return AffiliateTouchpoint::query()
            ->where('affiliate_attribution_id', $attribution->id)
            ->where('touchpoint_type', 'visit')
            ->where('url', $cleanUrl)
            ->where('touched_at', '>=', now()->subMinutes((int) config('dawah-share.visit_dedupe_minutes', 5)))
            ->exists();
    }

    private function recordOutboundShare(AffiliateLink $link, ?User $user, string $provider, ?Request $request = null): AffiliateTouchpoint
    {
        $affiliate = $link->affiliate()->first();
        $attribution = AffiliateAttribution::query()->firstOrCreate(
            [
                'affiliate_id' => $link->affiliate_id,
                'cookie_value' => null,
                'subject_type' => $link->subject_type,
                'subject_key' => $link->subject_key ?? 'link:'.$link->id,
                'subject_id' => $link->subject_id,
                'subject_instance' => $link->subject_instance ?? 'default',
            ],
            [
                'affiliate_code' => (string) $affiliate?->code,
                'user_id' => $user?->id,
                'affiliate_link_id' => $link->id,
                'subject_type' => $link->subject_type,
                'subject_id' => $link->subject_id,
                'subject_instance' => $link->subject_instance ?? 'default',
                'subject_key' => $link->subject_key,
                'origin' => $link->origin,
                'subject_title_snapshot' => $link->subject_title_snapshot,
                'attribution_type' => 'outbound_share',
                'channel' => $provider,
                'sharer_user_id' => $user?->id,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ],
        );

        $link->incrementClicks();

        return AffiliateTouchpoint::query()->create([
            'affiliate_attribution_id' => $attribution->id,
            'affiliate_id' => $link->affiliate_id,
            'affiliate_code' => (string) $affiliate?->code,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'touchpoint_type' => 'outbound_share',
            'affiliate_link_id' => $link->id,
            'subject_type' => $link->subject_type,
            'subject_key' => $link->subject_key,
            'subject_id' => $link->subject_id,
            'subject_instance' => $link->subject_instance ?? 'default',
            'subject_title_snapshot' => $link->subject_title_snapshot,
            'visitor_key' => $attribution->visitor_key,
            'channel' => $provider,
            'origin' => $attribution->origin,
            'referrer_url' => $request?->headers->get('referer'),
            'touched_at' => now(),
        ]);
    }

    private function ownerForAffiliateLink(AffiliateLink $link): ?User
    {
        $affiliate = $link->relationLoaded('affiliate') ? $link->affiliate : $link->affiliate()->first();

        return $this->ownerForAffiliate($affiliate);
    }

    private function ownerForAffiliateAttribution(AffiliateAttribution $attribution): ?User
    {
        $affiliate = $attribution->relationLoaded('affiliate') ? $attribution->affiliate : $attribution->affiliate()->first();

        return $this->ownerForAffiliate($affiliate);
    }

    private function ownerForAffiliate(?Affiliate $affiliate): ?User
    {
        $owner = $affiliate?->owner;

        return $owner instanceof User ? $owner : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function linkMetadata(AffiliateLink $link): array
    {
        $affiliate = $link->relationLoaded('affiliate') ? $link->affiliate : $link->affiliate()->first();
        $owner = $affiliate instanceof Affiliate ? $affiliate->owner : null;

        return array_filter([
            'share_token' => $link->custom_slug,
            'sharer_user_id' => $owner instanceof User ? $owner->getAuthIdentifier() : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function dispatchAffiliateAttributed(AffiliateAttribution $attribution): void
    {
        try {
            $this->affiliateSignalsBridge->recordAffiliateAttributed($attribution);
        } catch (Throwable $exception) {
            report($exception);
            logger()->warning('Signals affiliate attribution telemetry skipped after ingestion failure.', [
                'affiliate_attribution_id' => $attribution->getKey(),
                'affiliate_id' => $attribution->affiliate_id,
            ]);
        }
    }

    private function dispatchAffiliateConversionRecorded(AffiliateConversion $conversion): void
    {
        try {
            $this->affiliateSignalsBridge->recordAffiliateConversionRecorded($conversion);
        } catch (Throwable $exception) {
            report($exception);
            logger()->warning('Signals affiliate conversion telemetry skipped after ingestion failure.', [
                'affiliate_conversion_id' => $conversion->getKey(),
                'affiliate_id' => $conversion->affiliate_id,
                'conversion_type' => $conversion->conversion_type,
            ]);
        }
    }

    private function mapLink(AffiliateLink $link): ShareTrackingLinkData
    {
        return new ShareTrackingLinkData(
            id: (string) $link->id,
            backend: 'affiliates',
            subjectType: (string) ($link->subject_type ?: 'page'),
            subjectId: $this->nullableString($link->subject_id),
            subjectKey: (string) ($link->subject_key ?: 'page:unknown'),
            destinationUrl: (string) $link->destination_url,
            canonicalUrl: (string) $link->tracking_url,
            titleSnapshot: (string) ($link->subject_title_snapshot ?: config('app.name')),
            lastSharedAt: $link->updated_at,
        );
    }

    private function mapAttribution(AffiliateAttribution $attribution): ShareTrackingAttributionData
    {
        return new ShareTrackingAttributionData(
            id: (string) $attribution->id,
            backend: 'affiliates',
            linkId: $this->nullableString($attribution->affiliate_link_id) ?? '',
            visitorKey: $attribution->visitor_key,
            cookieValue: $attribution->cookie_value,
            landingUrl: $attribution->landing_url,
            shareProvider: $attribution->channel,
            subjectType: $attribution->subject_type,
            subjectId: $this->nullableString($attribution->subject_id) ?? $this->nullableString($attribution->subject_key),
            subjectKey: $attribution->subject_key,
            titleSnapshot: $attribution->subject_title_snapshot,
            firstSeenAt: $attribution->first_seen_at,
            lastSeenAt: $attribution->last_seen_at,
            expiresAt: $attribution->expires_at,
            metadata: $attribution->metadata ?? [],
        );
    }

    private function mapOutcome(AffiliateConversion $conversion): ShareTrackingOutcomeData
    {
        return new ShareTrackingOutcomeData(
            id: (string) $conversion->id,
            backend: 'affiliates',
            linkId: $this->nullableString($conversion->affiliate_link_id) ?? '',
            attributionId: $conversion->affiliate_attribution_id,
            sharerUserId: $conversion->sharer_user_id,
            actorUserId: $conversion->actor_user_id,
            outcomeType: (string) ($conversion->conversion_type ?: 'unknown'),
            subjectType: $conversion->subject_type,
            subjectId: $this->nullableString($conversion->subject_id),
            subjectKey: $conversion->subject_key,
            outcomeKey: $this->nullableString($conversion->external_reference) ?? '',
            linkTitleSnapshot: $this->nullableString($conversion->subject_title_snapshot),
            occurredAt: $conversion->occurred_at,
            metadata: $conversion->metadata ?? [],
        );
    }

    /**
     * @return array{subject_type: string, subject_id: string|null, subject_key: string, title_snapshot: string}
     */
    private function resolveOutcomeSubjectData(?Model $subject, AffiliateAttribution $attribution, ?AffiliateLink $link): array
    {
        if ($subject instanceof Model) {
            $classifiedSubject = $this->shareTrackingUrlService->classifySubjectModel($subject);

            return [
                'subject_type' => $classifiedSubject['subject_type'],
                'subject_id' => is_string($classifiedSubject['subject_id'] ?? null) ? $classifiedSubject['subject_id'] : null,
                'subject_key' => $classifiedSubject['subject_key'],
                'title_snapshot' => $this->subjectTitleSnapshotForModel($subject)
                    ?? $this->nullableString($link?->subject_title_snapshot)
                    ?? $this->nullableString($attribution->subject_title_snapshot)
                    ?? (string) config('app.name'),
            ];
        }

        return [
            'subject_type' => $this->nullableString($attribution->subject_type) ?? 'page',
            'subject_id' => $this->nullableString($attribution->subject_id),
            'subject_key' => $this->nullableString($attribution->subject_key) ?? 'page:unknown',
            'title_snapshot' => $this->nullableString($attribution->subject_title_snapshot)
                ?? $this->nullableString($link?->subject_title_snapshot)
                ?? (string) config('app.name'),
        ];
    }

    private function subjectTitleSnapshotForModel(Model $subject): ?string
    {
        return match (true) {
            $subject instanceof Event => $this->nullableString($subject->title),
            $subject instanceof Institution => $this->nullableString($subject->name),
            $subject instanceof Person => $this->nullableString($subject->formatted_name),
            $subject instanceof Series => $this->nullableString($subject->title),
            $subject instanceof Reference => $this->nullableString($subject->title),
            default => null,
        };
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function shouldIgnoreRequest(Request $request): bool
    {
        return $request->is('livewire/*')
            || $request->is('_debugbar/*')
            || $request->is('filament/*')
            || $request->is('api/*')
            || $request->routeIs('dawah-share.payload')
            || $request->routeIs('dawah-share.redirect');
    }

    private function isBotRequest(Request $request): bool
    {
        return $this->shareTrackingUrlService->isBotRequest($request);
    }

    private function expiryTimestamp(): ?CarbonInterface
    {
        $ttlDays = (int) config('dawah-share.ttl_days', 30);

        return $ttlDays > 0 ? now()->addDays($ttlDays) : null;
    }

    private function encodeCookieState(string $visitorKey, string $attributionCookie): string
    {
        return base64_encode(json_encode([
            'visitor_key' => $visitorKey,
            'attribution_cookie' => $attributionCookie,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{visitor_key?: string, attribution_cookie?: string, encoded?: string}
     */
    private function readCookieState(Request $request): array
    {
        $cookieName = (string) config('dawah-share.cookie.name', 'mi_dawah_share');
        $encoded = $request->cookie($cookieName);

        if (! is_string($encoded) || $encoded === '') {
            return [];
        }

        $payload = $this->decodeCookieStatePayload($encoded);

        if (is_array($payload) && ! $this->isDecodedCookieStatePayload($payload)) {
            $payload = null;
        }

        if (! is_array($payload)) {
            try {
                $decrypted = app('encrypter')->decrypt($encoded, false);
            } catch (Throwable) {
                $decrypted = null;
            }

            if (! is_string($decrypted) || $decrypted === '') {
                return [];
            }

            $decrypted = CookieValuePrefix::validate($cookieName, $decrypted, app('encrypter')->getAllKeys());

            if (! is_string($decrypted) || $decrypted === '') {
                return [];
            }

            $payload = $this->decodeCookieStatePayload($decrypted);

            if (! is_array($payload) || ! $this->isDecodedCookieStatePayload($payload)) {
                return [];
            }
        }

        return [
            'visitor_key' => is_string($payload['visitor_key'] ?? null) ? $payload['visitor_key'] : null,
            'attribution_cookie' => is_string($payload['attribution_cookie'] ?? null) ? $payload['attribution_cookie'] : null,
            'encoded' => $encoded,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeCookieStatePayload(string $encoded): ?array
    {
        $decoded = base64_decode($encoded, true);

        if (! is_string($decoded) || $decoded === '') {
            return null;
        }

        try {
            $payload = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isDecodedCookieStatePayload(array $payload): bool
    {
        return is_string($payload['visitor_key'] ?? null)
            && $payload['visitor_key'] !== ''
            && is_string($payload['attribution_cookie'] ?? null)
            && $payload['attribution_cookie'] !== '';
    }

    /**
     * @param  array<string, scalar|array<array-key, scalar|null>|null>  $parameters
     */
    private function appendQueryParameters(string $url, array $parameters): string
    {
        return $this->shareTrackingUrlService->appendQueryParameters($url, $parameters);
    }

    private function cleanTrackedUrl(string $url): string
    {
        return $this->shareTrackingUrlService->cleanTrackedUrl($url);
    }

    private function shareProviderFromRequest(Request $request): ?string
    {
        $provider = $request->query((string) config('dawah-share.provider_query_parameter', 'channel'));

        return is_string($provider) ? $this->shareTrackingUrlService->normalizeProvider($provider) : null;
    }

    /**
     * @return array{title: string, text: string, url: string, message: string}
     */
    private function nativeSharePayload(string $url, string $shareText, ?string $fallbackTitle = null): array
    {
        $resolvedTitle = filled($fallbackTitle) ? $fallbackTitle : $shareText;
        $resolvedMessage = trim($shareText."\n".$url);

        return [
            'title' => $resolvedTitle,
            'text' => $shareText,
            'url' => $url,
            'message' => $resolvedMessage,
        ];
    }

    private function resolveShareOrigin(?string $origin): string
    {
        return $this->shareTrackingUrlService->normalizeOrigin($origin) ?? 'web';
    }

    private function shareOriginForLink(AffiliateLink $link): string
    {
        return $this->resolveShareOrigin($link->origin);
    }
}
