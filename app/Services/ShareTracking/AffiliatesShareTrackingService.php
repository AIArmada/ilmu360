<?php

declare(strict_types=1);

namespace App\Services\ShareTracking;

use AIArmada\Affiliates\Actions\Affiliates\CreateTrackingLink;
use AIArmada\Affiliates\Actions\Conversions\RecordAffiliateOutcome;
use AIArmada\Affiliates\Data\AffiliateConversionData;
use AIArmada\Affiliates\Enums\AffiliateOutcomeDisposition;
use AIArmada\Affiliates\Enums\CommissionType;
use AIArmada\Affiliates\Enums\FraudSignalStatus;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateFraudSignal;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\Affiliates\States\Active;
use AIArmada\Affiliates\States\ApprovedConversion;
use AIArmada\Affiliates\States\ConversionStatus;
use AIArmada\Affiliates\Support\IpHasher;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerScope;
use AIArmada\Links\Models\Link;
use AIArmada\Rewards\Jobs\EvaluateRewardEntitlementJob;
use App\Data\ShareTracking\ShareTrackingAttributionData;
use App\Data\ShareTracking\ShareTrackingLinkData;
use App\Data\ShareTracking\ShareTrackingOutcomeData;
use App\Enums\DawahShareOutcomeType;
use App\Enums\ShareEffectStatus;
use App\Enums\ShareGuestClaimCapabilityStatus;
use App\Enums\ShareGuestClaimStatus;
use App\Enums\ShareGuestCredentialStatus;
use App\Jobs\Referrals\QualifyReferralFromShareOutcomeJob;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Series;
use App\Models\ShareBrowserSelection;
use App\Models\ShareEffectReceipt;
use App\Models\ShareGuestClaim;
use App\Models\ShareGuestClaimCapability;
use App\Models\ShareGuestCredential;
use App\Models\ShareOutcomeCorrection;
use App\Models\ShareSourceAttributionReceipt;
use App\Models\User;
use App\Support\ShareTracking\ActivityRewardBusinessKey;
use App\Support\ShareTracking\SealedGuestIdentityException;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use App\Support\ShareTracking\ShareProfileNotEligibleException;
use App\Support\ShareTracking\ShareTrackingFinance;
use App\Support\ShareTracking\ShareTrackingMeasurement;
use App\Support\ShareTracking\ShareTrackingProfile;
use Carbon\CarbonInterface;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

final readonly class AffiliatesShareTrackingService
{
    private const int SHARE_TOKEN_LENGTH = 16;

    private const int GUEST_CREDENTIAL_LENGTH = 32;

    private const int CLAIM_CAPABILITY_LENGTH = 32;

    public function __construct(
        private ShareTrackingUrlService $shareTrackingUrlService,
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
            return OwnerContext::withOwner(null, function () use ($request, $url, $fallbackTitle, $resolvedOrigin, $shareText): array {
                [$link, $issuedCredential, $issuedClaimCapability] = $this->createOrReuseGuestAffiliateLink(
                    $request,
                    $url,
                    $fallbackTitle,
                    $resolvedOrigin,
                );

                return $this->buildTrackedSharePayload($link, $shareText, $fallbackTitle, $issuedCredential, $issuedClaimCapability);
            });
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
            try {
                return OwnerContext::withOwner($user, function () use ($provider, $user, $url, $shareText, $fallbackTitle, $origin, $request): string {
                    $link = $this->createOrReuseAffiliateLinkForAffiliate(
                        $this->ensureAffiliateForUser($user),
                        $url,
                        $fallbackTitle,
                        $origin,
                    );

                    DB::transaction(function () use ($link, $user, $provider, $request): void {
                        $lockedLink = $this->lockedEligibleShareLink($link);

                        if (! $lockedLink instanceof AffiliateLink) {
                            throw new InvalidArgumentException('This share link has been disabled. Ask an administrator to review it before sharing again.');
                        }

                        $this->recordOutboundShare($lockedLink, $user, $provider, $request);
                    });

                    return $this->shareTrackingUrlService->platformLinks($this->shortUrlForLink($link), $shareText)[$provider] ?? $url;
                });
            } catch (ShareProfileNotEligibleException) {
                $shareTarget = $this->shareTrackingUrlService->classifyShareTarget($url, $fallbackTitle);
                $shareUrl = $shareTarget['destination_url'];

                return $this->shareTrackingUrlService->platformLinks($shareUrl, $shareText)[$provider] ?? $shareUrl;
            }
        }

        if ($request instanceof Request) {
            return OwnerContext::withOwner(null, function () use ($provider, $url, $shareText, $fallbackTitle, $origin, $request): string {
                [$link] = $this->createOrReuseGuestAffiliateLink($request, $url, $fallbackTitle, $origin);

                DB::transaction(function () use ($link, $provider, $request): void {
                    $lockedLink = $this->lockedEligibleShareLink($link);

                    if (! $lockedLink instanceof AffiliateLink) {
                        throw new InvalidArgumentException('This share link has been disabled. Ask an administrator to review it before sharing again.');
                    }

                    $this->recordOutboundShare($lockedLink, null, $provider, $request);
                });

                return $this->shareTrackingUrlService->platformLinks($this->shortUrlForLink($link), $shareText)[$provider] ?? $url;
            });
        }

        $shareUrl = $this->shareTrackingUrlService->normalizeAbsoluteInternalUrl($url);

        return $this->shareTrackingUrlService->platformLinks($shareUrl, $shareText)[$provider] ?? $shareUrl;
    }

    public function attributedUrl(User $user, string $url, ?string $fallbackTitle = null, ?string $origin = null): string
    {
        return OwnerContext::withOwner($user, fn (): string => $this->shortUrlForLink($this->createOrReuseAffiliateLink($user, $url, $fallbackTitle, $origin)));
    }

    public function recordShareAction(
        string $provider,
        ?User $user,
        string $trackingToken,
        ?Request $request = null,
    ): void {
        $link = $this->resolveLinkFromTrackingToken($trackingToken, $user, $request);

        if (! $link instanceof AffiliateLink) {
            throw new InvalidArgumentException('The share tracking token is invalid. Refresh the page and try sharing again.');
        }

        $owner = $user ?? $this->ownerForAffiliateLink($link);

        DB::transaction(fn (): mixed => OwnerContext::withOwner($owner, function () use ($link, $user, $provider, $request): void {
            $lockedLink = $this->lockedEligibleShareLink($link);

            if (! $lockedLink instanceof AffiliateLink) {
                throw new InvalidArgumentException('The share tracking token is invalid. Refresh the page and try sharing again.');
            }

            $this->recordOutboundShare($lockedLink, $user, $provider, $request);
        }));
    }

    public function captureRequest(Request $request): ?string
    {
        $cookieState = $this->readCookieState($request);

        if (! $request->isMethod('GET') || $request->expectsJson() || $request->ajax() || $this->isBotRequest($request)) {
            return $cookieState['encoded'] ?? null;
        }

        if (! $this->shareTrackingUrlService->isPublicTrackableNavigation($request)) {
            return $cookieState['encoded'] ?? null;
        }

        $parameter = (string) config('dawah-share.query_parameter', 'share');
        $shareToken = $request->query($parameter);
        $incomingBrowserKey = $cookieState['visitor_key'] ?? null;
        $browserKey = is_string($incomingBrowserKey) && $incomingBrowserKey !== '' && mb_strlen($incomingBrowserKey) <= 160
            ? $incomingBrowserKey
            : (string) Str::ulid();
        $shareProvider = $this->shareProviderFromRequest($request);

        if (is_string($shareToken) && $shareToken !== '') {
            $link = $this->resolveLinkFromShareToken($shareToken);

            if (! $link instanceof AffiliateLink) {
                return DB::transaction(function () use ($browserKey): string {
                    $selection = $this->lockedExistingBrowserSelection($browserKey);

                    if ($selection instanceof ShareBrowserSelection) {
                        $this->clearBrowserSelection($browserKey, $selection);
                    }

                    return $this->encodeCookieState($browserKey, '');
                }, 3);
            }

            return DB::transaction(fn (): string => OwnerContext::withOwner($this->ownerForAffiliateLink($link), function () use ($link, $request, $browserKey, $shareProvider): string {
                $selection = $this->lockedBrowserSelection($browserKey);
                $lockedLink = $this->lockedEligibleShareLink($link);

                if (! $lockedLink instanceof AffiliateLink) {
                    $this->clearBrowserSelection($browserKey, $selection);

                    return $this->encodeCookieState($browserKey, '');
                }

                $current = $this->lockedBrowserWinner($selection, $browserKey);
                $safeUrl = $this->minimalPersistedUrl($request->fullUrl());
                $deduped = $this->dedupeVisitExists($browserKey, (string) $lockedLink->getKey(), $safeUrl);

                if ($deduped) {
                    if ($current instanceof AffiliateAttribution
                        && $this->isSameExplicitLanding($current, $lockedLink, $shareProvider)) {
                        return $this->encodeCookieState($browserKey, (string) $current->cookie_value);
                    }

                    $attribution = $this->createLandingAttribution($lockedLink, $request, $browserKey, $shareProvider);

                    $selection->forceFill([
                        'current_attribution_id' => $attribution->getKey(),
                        'accepted_at' => now(),
                    ])->save();

                    return $this->encodeCookieState($browserKey, (string) $attribution->cookie_value);
                }

                $attribution = $this->createLandingAttribution($lockedLink, $request, $browserKey, $shareProvider);

                $selection->forceFill([
                    'current_attribution_id' => $attribution->getKey(),
                    'accepted_at' => now(),
                ])->save();

                $this->recordVisitTouchpoint($attribution, $request, 'landing', $safeUrl);

                return $this->encodeCookieState($browserKey, (string) $attribution->cookie_value);
            }), 3);
        }

        $navigationBrowserKey = $this->browserKeyFromRequest($request);

        if ($navigationBrowserKey === null) {
            return $cookieState['encoded'] ?? null;
        }

        return DB::transaction(function () use ($request, $navigationBrowserKey): string {
            $selection = $this->lockedExistingBrowserSelection($navigationBrowserKey);

            if (! $selection instanceof ShareBrowserSelection) {
                return $this->encodeCookieState($navigationBrowserKey, '');
            }

            $winner = $this->lockedBrowserWinner($selection, $navigationBrowserKey);

            if (! $winner instanceof AffiliateAttribution) {
                $this->clearBrowserSelection($navigationBrowserKey, $selection);

                return $this->encodeCookieState($navigationBrowserKey, '');
            }

            return OwnerContext::withOwner($this->ownerForAffiliateAttribution($winner), function () use ($winner, $request, $navigationBrowserKey): string {
                $safeUrl = $this->minimalPersistedUrl($request->fullUrl());
                $linkId = (string) $winner->affiliate_link_id;

                if ($linkId !== '' && $this->dedupeVisitExists($navigationBrowserKey, $linkId, $safeUrl)) {
                    return $this->encodeCookieState($navigationBrowserKey, (string) $winner->cookie_value);
                }

                $winner->forceFill([
                    'last_seen_at' => now(),
                    'last_cookie_seen_at' => now(),
                ])->save();

                $kind = $safeUrl === (string) $winner->landing_url ? 'return' : 'navigated';
                $this->recordVisitTouchpoint($winner, $request, $kind, $safeUrl);

                return $this->encodeCookieState($navigationBrowserKey, (string) $winner->cookie_value);
            });
        }, 3);
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
            businessKey: ShareOutcomeBusinessKey::signup((string) $user->getKey()),
            actor: $user,
            request: $request,
            metadata: [
                'signed_up_user_id' => $user->getKey(),
            ],
            occurredAt: $user->created_at ?? now(),
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordOutcome(
        DawahShareOutcomeType $type,
        string $businessKey,
        ?Model $subject = null,
        ?User $actor = null,
        ?Request $request = null,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null,
    ): ?ShareTrackingOutcomeData {
        $parsed = ShareOutcomeBusinessKey::parse($businessKey, $type);
        $businessKey = $parsed['namespace'].':'.$parsed['outcome_type'].':'.$parsed['source_transition_id'];
        $sourceTransitionId = $parsed['source_transition_id'];
        $occurredAt ??= now();

        $receipt = ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->first();

        if ($receipt instanceof ShareSourceAttributionReceipt) {
            $this->dispatchReferralQualification($type, $businessKey);
            $this->dispatchActivityRewardIfUnattributed($type, $sourceTransitionId, $businessKey);

            return $this->mapReceiptReplay($receipt, $businessKey);
        }

        $browserKey = $this->browserKeyFromRequest($request);

        $outcome = DB::transaction(function () use ($browserKey, $type, $businessKey, $sourceTransitionId, $subject, $metadata, $actor, $occurredAt): ?ShareTrackingOutcomeData {
            $selection = is_string($browserKey) ? $this->lockedExistingBrowserSelection($browserKey) : null;
            $winner = $selection instanceof ShareBrowserSelection && is_string($browserKey)
                ? $this->lockedBrowserWinner($selection, $browserKey)
                : null;

            if ($selection instanceof ShareBrowserSelection && is_string($browserKey) && ! $winner instanceof AffiliateAttribution && $selection->current_attribution_id !== null) {
                $this->clearBrowserSelection($browserKey, $selection);
            }

            $owner = $winner instanceof AffiliateAttribution
                ? $this->ownerForAffiliateAttribution($winner)
                : null;

            return OwnerContext::withOwner($owner, function () use ($winner, $type, $businessKey, $sourceTransitionId, $subject, $metadata, $actor, $occurredAt): ?ShareTrackingOutcomeData {
                $existingReceipt = ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->lockForUpdate()->first();

                if ($existingReceipt instanceof ShareSourceAttributionReceipt) {
                    return $this->mapReceiptReplay($existingReceipt, $businessKey);
                }

                $existingConversion = AffiliateConversion::query()
                    ->where('idempotency_key', hash('sha256', $businessKey))
                    ->lockForUpdate()
                    ->first();

                if ($existingConversion instanceof AffiliateConversion) {
                    $storedAttribution = $existingConversion->affiliate_attribution_id !== null
                        ? AffiliateAttribution::query()->withoutGlobalScope(OwnerScope::class)->whereKey($existingConversion->affiliate_attribution_id)->first()
                        : null;

                    if ($storedAttribution instanceof AffiliateAttribution) {
                        $this->freezeAttributedReceipt($type, $businessKey, $sourceTransitionId, $storedAttribution, ShareSourceAttributionReceipt::PROVENANCE_SYSTEM, $occurredAt);
                    } else {
                        $this->freezeUnattributedReceipt($type, $businessKey, $sourceTransitionId, ShareSourceAttributionReceipt::PROVENANCE_SYSTEM, $occurredAt);
                    }

                    if ($this->isInvalidConversion($existingConversion)) {
                        return null;
                    }

                    return $this->mapOutcome($existingConversion);
                }

                if (! $winner instanceof AffiliateAttribution) {
                    $this->freezeUnattributedReceipt($type, $businessKey, $sourceTransitionId, ShareSourceAttributionReceipt::PROVENANCE_COOKIE, $occurredAt);

                    return null;
                }

                $lockedLink = $this->lockedEligibleShareLink($winner->affiliate_link_id);

                if (! $lockedLink instanceof AffiliateLink
                    || (string) $winner->affiliate_id !== (string) $lockedLink->affiliate_id) {
                    $this->freezeUnattributedReceipt($type, $businessKey, $sourceTransitionId, ShareSourceAttributionReceipt::PROVENANCE_COOKIE, $occurredAt);

                    return null;
                }

                /** @var Affiliate $lockedAffiliate */
                $lockedAffiliate = $lockedLink->affiliate;

                if ($this->isProvenSelfCredit($lockedAffiliate, $winner, $actor)) {
                    $this->freezeUnattributedReceipt($type, $businessKey, $sourceTransitionId, ShareSourceAttributionReceipt::PROVENANCE_COOKIE, $occurredAt);

                    return null;
                }

                $frozen = $this->freezeAttributedReceipt($type, $businessKey, $sourceTransitionId, $winner, ShareSourceAttributionReceipt::PROVENANCE_COOKIE, $occurredAt);

                if (! $frozen->isAttributed() || (string) $frozen->attribution_id !== (string) $winner->getKey()) {
                    return $this->mapReceiptReplay($frozen, $businessKey);
                }

                $linkId = $this->nullableString($winner->affiliate_link_id) ?? '';
                $sharerUserId = $this->nullableString($winner->sharer_user_id);
                $link = $linkId !== '' ? AffiliateLink::query()->find($linkId) : null;
                $subjectData = $this->resolveOutcomeSubjectData($subject, $winner, $link);

                $result = $this->recordAffiliateOutcome->handle(
                    attribution: $winner,
                    conversionType: $type->value,
                    businessKey: $businessKey,
                    payload: [
                        'subject_type' => $subjectData['subject_type'],
                        'subject_key' => $subjectData['subject_key'],
                        'subject_id' => $subjectData['subject_id'],
                        'subject_instance' => 'default',
                        'subject_title_snapshot' => Str::limit($subjectData['title_snapshot'], 200, ''),
                        'commission_currency' => (string) config('affiliates.currency.default', 'MYR'),
                        'status' => ApprovedConversion::class,
                        'channel' => $winner->channel,
                        'origin' => $winner->origin,
                        'affiliate_link_id' => $winner->affiliate_link_id,
                        'sharer_user_id' => $sharerUserId ?: null,
                        'actor_user_id' => $actor?->getKey(),
                        'occurred_at' => $occurredAt,
                        'approved_at' => $occurredAt,
                        'dispatch_event' => false,
                        'metadata' => $metadata,
                    ],
                );

                if ($result->disposition === AffiliateOutcomeDisposition::NoAffiliate) {
                    return null;
                }

                if ($result->disposition === AffiliateOutcomeDisposition::Replayed) {
                    $stored = $result->conversion instanceof AffiliateConversionData
                        ? AffiliateConversion::query()->whereKey($result->conversion->id)->first()
                        : null;

                    if (! $stored instanceof AffiliateConversion || $this->isInvalidConversion($stored)) {
                        return null;
                    }

                    return $this->mapOutcome($stored);
                }

                if ($result->disposition === AffiliateOutcomeDisposition::Rejected) {
                    return null;
                }

                $conversion = $result->conversion instanceof AffiliateConversionData
                    ? AffiliateConversion::query()->whereKey($result->conversion->id)->firstOrFail()
                    : AffiliateConversion::query()->where('idempotency_key', hash('sha256', $businessKey))->firstOrFail();

                $this->freezeEffectReceipt($businessKey, $conversion, $linkId);

                return $this->mapOutcome($conversion);
            });
            // Concurrent same-key inserts deadlock on gap locks under
            // InnoDB; the retry replays the committed winner decision.
        }, 3);

        $this->dispatchReferralQualification($type, $businessKey);
        $this->dispatchActivityRewardIfUnattributed($type, $sourceTransitionId, $businessKey);

        return $outcome;
    }

    private function dispatchActivityRewardIfUnattributed(
        DawahShareOutcomeType $type,
        string $sourceTransitionId,
        string $shareBusinessKey,
    ): void {
        $receipt = ShareSourceAttributionReceipt::query()
            ->where('business_key', $shareBusinessKey)
            ->first();

        if (! $receipt instanceof ShareSourceAttributionReceipt || $receipt->isAttributed()) {
            return;
        }

        $activityBusinessKey = ActivityRewardBusinessKey::for($type, $sourceTransitionId);

        EvaluateRewardEntitlementJob::dispatch($activityBusinessKey)->afterCommit();
    }

    private function dispatchReferralQualification(DawahShareOutcomeType $type, string $businessKey): void
    {
        if (! in_array($type, [DawahShareOutcomeType::EventRegistration, DawahShareOutcomeType::EventCheckin], true)) {
            return;
        }

        try {
            QualifyReferralFromShareOutcomeJob::dispatch($businessKey)->afterCommit();
        } catch (Throwable $exception) {
            Log::warning('Referral qualification dispatch failed after a committed share outcome.', [
                'business_key' => $businessKey,
                'exception' => $exception,
            ]);
        }
    }

    private function mapReceiptReplay(ShareSourceAttributionReceipt $receipt, string $businessKey): ?ShareTrackingOutcomeData
    {
        if (! $receipt->isAttributed() || $receipt->attribution_id === null) {
            return null;
        }

        $conversion = AffiliateConversion::query()->where('idempotency_key', hash('sha256', $businessKey))->first();

        if (! $conversion instanceof AffiliateConversion || $this->isInvalidConversion($conversion)) {
            return null;
        }

        return $this->mapOutcome($conversion);
    }

    private function isInvalidConversion(AffiliateConversion $conversion): bool
    {
        $status = $conversion->status;

        $value = $status instanceof ConversionStatus
            ? $status->getValue()
            : (string) $status;

        return in_array($value, ['rejected', 'reversed'], true);
    }

    private function freezeUnattributedReceipt(
        DawahShareOutcomeType $type,
        string $businessKey,
        string $sourceTransitionId,
        string $provenance,
        CarbonInterface $occurredAt,
    ): ShareSourceAttributionReceipt {
        try {
            return DB::transaction(static fn (): ShareSourceAttributionReceipt => ShareSourceAttributionReceipt::query()->create([
                'namespace' => ShareOutcomeBusinessKey::NAMESPACE,
                'outcome_type' => $type->value,
                'source_transition_id' => $sourceTransitionId,
                'business_key' => $businessKey,
                'attribution_id' => null,
                'provenance' => $provenance,
                'definition_version' => ShareTrackingMeasurement::definitionVersion(),
                'occurred_at' => $occurredAt,
            ]), 1);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            return ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->firstOrFail();
        }
    }

    private function freezeAttributedReceipt(
        DawahShareOutcomeType $type,
        string $businessKey,
        string $sourceTransitionId,
        AffiliateAttribution $attribution,
        string $provenance,
        CarbonInterface $occurredAt,
    ): ShareSourceAttributionReceipt {
        try {
            return DB::transaction(static fn (): ShareSourceAttributionReceipt => ShareSourceAttributionReceipt::query()->create([
                'namespace' => ShareOutcomeBusinessKey::NAMESPACE,
                'outcome_type' => $type->value,
                'source_transition_id' => $sourceTransitionId,
                'business_key' => $businessKey,
                'attribution_id' => $attribution->getKey(),
                'provenance' => $provenance,
                'definition_version' => ShareTrackingMeasurement::definitionVersion(),
                'occurred_at' => $occurredAt,
            ]), 1);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            return ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->firstOrFail();
        }
    }

    private function freezeEffectReceipt(string $businessKey, AffiliateConversion $conversion, string $linkId): ShareEffectReceipt
    {
        $effectKey = $businessKey.':projection';

        try {
            return DB::transaction(static fn (): ShareEffectReceipt => ShareEffectReceipt::query()->create([
                'effect_key' => $effectKey,
                'source_type' => ShareOutcomeBusinessKey::NAMESPACE,
                'source_id' => $businessKey,
                'effect_type' => 'projection',
                'status' => ShareEffectStatus::Pending,
                'payload' => [
                    'conversion_id' => (string) $conversion->getKey(),
                    'affiliate_id' => (string) $conversion->affiliate_id,
                    'link_id' => $linkId,
                ],
                'attempts' => 0,
                'lease_token' => null,
                'leased_by' => null,
                'lease_expires_at' => null,
                'succeeded_at' => null,
                'failed_at' => null,
                'cancelled_at' => null,
            ]), 1);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            return ShareEffectReceipt::query()->where('effect_key', $effectKey)->firstOrFail();
        }
    }

    /**
     * Record an outcome inheriting frozen source provenance, never the
     * current browser cookie. Used for registration-linked attendance
     * (self or staff-assisted), including attendance later than 30 days.
     * Current link disablement cannot erase accrued provenance.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordInheritedOutcome(
        DawahShareOutcomeType $type,
        string $businessKey,
        string $sourceBusinessKey,
        ?Model $subject = null,
        ?User $actor = null,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null,
    ): ?ShareTrackingOutcomeData {
        $parsed = ShareOutcomeBusinessKey::parse($businessKey, $type);
        $businessKey = $parsed['namespace'].':'.$parsed['outcome_type'].':'.$parsed['source_transition_id'];
        $sourceTransitionId = $parsed['source_transition_id'];
        $occurredAt ??= now();

        $sourceBusinessKey = trim($sourceBusinessKey);

        if ($sourceBusinessKey === '' || mb_strlen($sourceBusinessKey) > 191) {
            throw new InvalidArgumentException('Source business key must be a non-empty bounded identity.');
        }

        $this->assertValidInheritancePairing($type, $sourceBusinessKey);

        $receipt = ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->first();

        if ($receipt instanceof ShareSourceAttributionReceipt) {
            $this->dispatchReferralQualification($type, $businessKey);
            $this->dispatchActivityRewardIfUnattributed($type, $sourceTransitionId, $businessKey);

            return $this->mapReceiptReplay($receipt, $businessKey);
        }

        $outcome = DB::transaction(function () use ($type, $businessKey, $sourceTransitionId, $sourceBusinessKey, $subject, $metadata, $actor, $occurredAt): ?ShareTrackingOutcomeData {
            $sourceAttribution = $this->lockedValidInheritanceSource($sourceBusinessKey);

            $owner = $sourceAttribution instanceof AffiliateAttribution
                ? $this->ownerForAffiliateAttribution($sourceAttribution)
                : null;

            return OwnerContext::withOwner($owner, function () use ($sourceAttribution, $type, $businessKey, $sourceTransitionId, $subject, $metadata, $actor, $occurredAt): ?ShareTrackingOutcomeData {
                $existingReceipt = ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->lockForUpdate()->first();

                if ($existingReceipt instanceof ShareSourceAttributionReceipt) {
                    return $this->mapReceiptReplay($existingReceipt, $businessKey);
                }

                $existingConversion = AffiliateConversion::query()
                    ->where('idempotency_key', hash('sha256', $businessKey))
                    ->lockForUpdate()
                    ->first();

                if ($existingConversion instanceof AffiliateConversion) {
                    $storedAttribution = $existingConversion->affiliate_attribution_id !== null
                        ? AffiliateAttribution::query()->withoutGlobalScope(OwnerScope::class)->whereKey($existingConversion->affiliate_attribution_id)->first()
                        : null;

                    if ($storedAttribution instanceof AffiliateAttribution) {
                        $this->freezeAttributedReceipt($type, $businessKey, $sourceTransitionId, $storedAttribution, ShareSourceAttributionReceipt::PROVENANCE_SYSTEM, $occurredAt);
                    } else {
                        $this->freezeUnattributedReceipt($type, $businessKey, $sourceTransitionId, ShareSourceAttributionReceipt::PROVENANCE_SYSTEM, $occurredAt);
                    }

                    if ($this->isInvalidConversion($existingConversion)) {
                        return null;
                    }

                    return $this->mapOutcome($existingConversion);
                }

                if (! $sourceAttribution instanceof AffiliateAttribution) {
                    $this->freezeUnattributedReceipt($type, $businessKey, $sourceTransitionId, ShareSourceAttributionReceipt::PROVENANCE_REGISTRATION, $occurredAt);

                    return null;
                }

                $attribution = $sourceAttribution;

                $affiliate = $attribution->relationLoaded('affiliate')
                    ? $attribution->affiliate
                    : $attribution->affiliate()->first();

                if ($affiliate instanceof Affiliate && $this->isProvenSelfCredit($affiliate, $attribution, $actor)) {
                    $this->freezeUnattributedReceipt($type, $businessKey, $sourceTransitionId, ShareSourceAttributionReceipt::PROVENANCE_REGISTRATION, $occurredAt);

                    return null;
                }

                $frozen = $this->freezeAttributedReceipt($type, $businessKey, $sourceTransitionId, $attribution, ShareSourceAttributionReceipt::PROVENANCE_REGISTRATION, $occurredAt);

                if (! $frozen->isAttributed() || (string) $frozen->attribution_id !== (string) $attribution->getKey()) {
                    return $this->mapReceiptReplay($frozen, $businessKey);
                }

                $linkId = $this->nullableString($attribution->affiliate_link_id) ?? '';
                $sharerUserId = $this->nullableString($attribution->sharer_user_id);
                $link = $linkId !== '' ? AffiliateLink::query()->find($linkId) : null;
                $subjectData = $this->resolveOutcomeSubjectData($subject, $attribution, $link);

                $result = $this->recordAffiliateOutcome->handle(
                    attribution: $attribution,
                    conversionType: $type->value,
                    businessKey: $businessKey,
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
                        'actor_user_id' => $actor?->getKey(),
                        'occurred_at' => $occurredAt,
                        'approved_at' => $occurredAt,
                        'dispatch_event' => false,
                        'metadata' => $metadata,
                    ],
                );

                if ($result->disposition === AffiliateOutcomeDisposition::NoAffiliate) {
                    return null;
                }

                if ($result->disposition === AffiliateOutcomeDisposition::Replayed) {
                    $stored = $result->conversion instanceof AffiliateConversionData
                        ? AffiliateConversion::query()->whereKey($result->conversion->id)->first()
                        : null;

                    if (! $stored instanceof AffiliateConversion || $this->isInvalidConversion($stored)) {
                        return null;
                    }

                    return $this->mapOutcome($stored);
                }

                if ($result->disposition === AffiliateOutcomeDisposition::Rejected) {
                    return null;
                }

                $conversion = $result->conversion instanceof AffiliateConversionData
                    ? AffiliateConversion::query()->whereKey($result->conversion->id)->firstOrFail()
                    : AffiliateConversion::query()->where('idempotency_key', hash('sha256', $businessKey))->firstOrFail();

                $this->freezeEffectReceipt($businessKey, $conversion, $linkId);

                return $this->mapOutcome($conversion);
            });
            // Concurrent same-key inserts deadlock on gap locks under
            // InnoDB; the retry replays the committed winner decision.
        }, 3);

        $this->dispatchReferralQualification($type, $businessKey);
        $this->dispatchActivityRewardIfUnattributed($type, $sourceTransitionId, $businessKey);

        return $outcome;
    }

    /**
     * Record an outcome inheriting the frozen paid-checkout intent decision,
     * never the current browser cookie or a late token. Used for
     * order-driven registrations created after payment confirmation: the
     * persisted attribution id was captured once on the authoritative
     * server-created checkout session before the external payment handoff.
     *
     * Unknown persisted refs fail closed to explicitly unattributed, and
     * source-linked policy (proven self-credit) plus real fraud/accounting
     * stay enforced at freeze time. Capture-time eligibility (link enabled,
     * TTL) is not rechecked: accrued provenance survives later link
     * disablement, like registration inheritance.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordCheckoutInheritedOutcome(
        DawahShareOutcomeType $type,
        string $businessKey,
        ?string $persistedAttributionId,
        ?Model $subject = null,
        ?User $actor = null,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null,
    ): ?ShareTrackingOutcomeData {
        $parsed = ShareOutcomeBusinessKey::parse($businessKey, $type);
        $businessKey = $parsed['namespace'].':'.$parsed['outcome_type'].':'.$parsed['source_transition_id'];
        $sourceTransitionId = $parsed['source_transition_id'];
        $occurredAt ??= now();

        $persistedAttributionId = is_string($persistedAttributionId) ? trim($persistedAttributionId) : '';

        $receipt = ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->first();

        if ($receipt instanceof ShareSourceAttributionReceipt) {
            return $this->mapReceiptReplay($receipt, $businessKey);
        }

        return DB::transaction(function () use ($persistedAttributionId, $type, $businessKey, $sourceTransitionId, $subject, $metadata, $actor, $occurredAt): ?ShareTrackingOutcomeData {
            $attribution = $persistedAttributionId !== '' && mb_strlen($persistedAttributionId) <= 64
                ? AffiliateAttribution::query()->withoutGlobalScope(OwnerScope::class)->whereKey($persistedAttributionId)->lockForUpdate()->first()
                : null;

            if ($attribution instanceof AffiliateAttribution && (string) $attribution->attribution_type !== 'landing') {
                $attribution = null;
            }

            $owner = $attribution instanceof AffiliateAttribution
                ? $this->ownerForAffiliateAttribution($attribution)
                : null;

            return OwnerContext::withOwner($owner, function () use ($attribution, $type, $businessKey, $sourceTransitionId, $subject, $metadata, $actor, $occurredAt): ?ShareTrackingOutcomeData {
                $existingReceipt = ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->lockForUpdate()->first();

                if ($existingReceipt instanceof ShareSourceAttributionReceipt) {
                    return $this->mapReceiptReplay($existingReceipt, $businessKey);
                }

                $existingConversion = AffiliateConversion::query()
                    ->where('idempotency_key', hash('sha256', $businessKey))
                    ->lockForUpdate()
                    ->first();

                if ($existingConversion instanceof AffiliateConversion) {
                    $storedAttribution = $existingConversion->affiliate_attribution_id !== null
                        ? AffiliateAttribution::query()->withoutGlobalScope(OwnerScope::class)->whereKey($existingConversion->affiliate_attribution_id)->first()
                        : null;

                    if ($storedAttribution instanceof AffiliateAttribution) {
                        $this->freezeAttributedReceipt($type, $businessKey, $sourceTransitionId, $storedAttribution, ShareSourceAttributionReceipt::PROVENANCE_SYSTEM, $occurredAt);
                    } else {
                        $this->freezeUnattributedReceipt($type, $businessKey, $sourceTransitionId, ShareSourceAttributionReceipt::PROVENANCE_SYSTEM, $occurredAt);
                    }

                    if ($this->isInvalidConversion($existingConversion)) {
                        return null;
                    }

                    return $this->mapOutcome($existingConversion);
                }

                if (! $attribution instanceof AffiliateAttribution) {
                    $this->freezeUnattributedReceipt($type, $businessKey, $sourceTransitionId, ShareSourceAttributionReceipt::PROVENANCE_CHECKOUT, $occurredAt);

                    return null;
                }

                $affiliate = $attribution->relationLoaded('affiliate')
                    ? $attribution->affiliate
                    : $attribution->affiliate()->first();

                if ($affiliate instanceof Affiliate && $this->isProvenSelfCredit($affiliate, $attribution, $actor)) {
                    $this->freezeUnattributedReceipt($type, $businessKey, $sourceTransitionId, ShareSourceAttributionReceipt::PROVENANCE_CHECKOUT, $occurredAt);

                    return null;
                }

                $frozen = $this->freezeAttributedReceipt($type, $businessKey, $sourceTransitionId, $attribution, ShareSourceAttributionReceipt::PROVENANCE_CHECKOUT, $occurredAt);

                if (! $frozen->isAttributed() || (string) $frozen->attribution_id !== (string) $attribution->getKey()) {
                    return $this->mapReceiptReplay($frozen, $businessKey);
                }

                $linkId = $this->nullableString($attribution->affiliate_link_id) ?? '';
                $sharerUserId = $this->nullableString($attribution->sharer_user_id);
                $link = $linkId !== '' ? AffiliateLink::query()->find($linkId) : null;
                $subjectData = $this->resolveOutcomeSubjectData($subject, $attribution, $link);

                $result = $this->recordAffiliateOutcome->handle(
                    attribution: $attribution,
                    conversionType: $type->value,
                    businessKey: $businessKey,
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
                        'actor_user_id' => $actor?->getKey(),
                        'occurred_at' => $occurredAt,
                        'approved_at' => $occurredAt,
                        'dispatch_event' => false,
                        'metadata' => $metadata,
                    ],
                );

                if ($result->disposition === AffiliateOutcomeDisposition::NoAffiliate) {
                    return null;
                }

                if ($result->disposition === AffiliateOutcomeDisposition::Replayed) {
                    $stored = $result->conversion instanceof AffiliateConversionData
                        ? AffiliateConversion::query()->whereKey($result->conversion->id)->first()
                        : null;

                    if (! $stored instanceof AffiliateConversion || $this->isInvalidConversion($stored)) {
                        return null;
                    }

                    return $this->mapOutcome($stored);
                }

                if ($result->disposition === AffiliateOutcomeDisposition::Rejected) {
                    return null;
                }

                $conversion = $result->conversion instanceof AffiliateConversionData
                    ? AffiliateConversion::query()->whereKey($result->conversion->id)->firstOrFail()
                    : AffiliateConversion::query()->where('idempotency_key', hash('sha256', $businessKey))->firstOrFail();

                $this->freezeEffectReceipt($businessKey, $conversion, $linkId);

                return $this->mapOutcome($conversion);
            });
            // Concurrent same-key inserts deadlock on gap locks under
            // InnoDB; the retry replays the committed winner decision.
        }, 3);
    }

    public function createOrReuseLink(User $user, string $url, ?string $fallbackTitle = null, ?string $origin = null): ShareTrackingLinkData
    {
        return OwnerContext::withOwner($user, fn (): ShareTrackingLinkData => $this->mapLink($this->createOrReuseAffiliateLink($user, $url, $fallbackTitle, $origin)));
    }

    public function deleteUserTracking(User $user): void
    {
        OwnerContext::withOwner($user, function () use ($user): void {
            // Claimant-scoped revocation runs first and unconditionally:
            // claims detach and their source identities disarm even when
            // the member profile is already gone. Serialized on the user
            // row — the same first lock the claim action takes — so a
            // racing claim either commits before (and is revoked here) or
            // observes the deletion honestly.
            DB::transaction(function () use ($user): void {
                User::query()->whereKey($user->getKey())->lockForUpdate()->first();
                $this->revokeGuestClaimsForClaimant($user);
                $this->disarmClaimedGuestSources($user);
            });

            $affiliate = $this->findAffiliateForUser($user);

            if (! $affiliate instanceof Affiliate) {
                return;
            }

            // Financial obligations are determined BEFORE any destructive
            // write. Monetary conversions (paid/reversed/payout-linked or
            // nonzero money/held counters, including signed reversal legs)
            // are never deleted by tracking cleanup, and the source facts
            // they reference stay as reward evidence.
            $preservedConversionIds = $this->monetaryConversionIds($affiliate);
            $referencedLinkIds = $this->preservedConversionReferenceIds($affiliate, $preservedConversionIds, 'affiliate_link_id');
            $referencedAttributionIds = $this->preservedConversionReferenceIds($affiliate, $preservedConversionIds, 'affiliate_attribution_id');
            $hasFinancialRecords = $preservedConversionIds !== [] || $this->affiliateHasNonConversionFinancialRecords($affiliate);

            $this->revokeGuestCredentials($affiliate);

            // Non-monetary conversions only; preserved rows keep their ids,
            // values and payout links untouched.
            $affiliate->conversions()
                ->when($preservedConversionIds !== [], fn ($query) => $query->whereNotIn('id', $preservedConversionIds))
                ->delete();

            // Attributions referenced by preserved conversions stay: model
            // deletion would null the preserved row's attribution reference.
            // The rest are model deletes so their touchpoints cascade.
            $affiliate->attributions()
                ->when($referencedAttributionIds !== [], fn ($query) => $query->whereNotIn('id', $referencedAttributionIds))
                ->each(fn (AffiliateAttribution $attribution): bool => (bool) $attribution->delete());

            $this->deleteUnreferencedTouchpoints($affiliate, $referencedLinkIds, $referencedAttributionIds);

            // Model deletes so the vendor callback removes the links-managed
            // tracked rows (and their clicks); a bulk delete would orphan them.
            // Each doomed share link first detaches its tracked row into a
            // canonical minimal public redirect: published short URLs keep
            // opening their public destination with credit and private
            // identity revoked, instead of 404ing with the owner.
            $affiliate->links()
                ->when($referencedLinkIds !== [], fn ($query) => $query->whereNotIn('id', $referencedLinkIds))
                ->each(function (AffiliateLink $link): ?bool {
                    $this->detachTrackedLinkForDeletion($link);

                    return $link->delete();
                });

            // Money and payout operations are never deleted by tracking
            // cleanup. When financial rows exist, the profile stays as the
            // restricted payee/ledger record needed for settlement, with its
            // referenced source facts kept but disarmed: retained links are
            // disabled and retained cookie capabilities are expired so no
            // new credit can accrue after the owner is gone.
            if ($hasFinancialRecords) {
                $this->disarmPreservedShareFacts($affiliate, $referencedLinkIds, $referencedAttributionIds);

                return;
            }

            $affiliate->balances()->delete();
            ShareGuestCredential::query()->where('affiliate_id', $affiliate->id)->delete();
            $affiliate->delete();
        });
    }

    /**
     * Delete abandoned anonymous share profiles past retention.
     *
     * A guest profile is abandoned when no claim row exists (any claim,
     * including a revoked one, permanently keeps the history), it has no
     * conversion, fraud signal, or financial row, and its creation predates
     * the retention cutoff. Links are detached first so published short URLs
     * keep opening their public destination without credit.
     *
     * @return array{scanned: int, pruned: int, skipped: int}
     */
    public function pruneAbandonedGuestProfiles(int $retentionDays, int $limit = 1000, bool $dryRun = false): array
    {
        if ($retentionDays < 1) {
            throw new InvalidArgumentException('Guest profile retention must be at least one day.');
        }

        $cutoff = now()->subDays($retentionDays);

        $candidates = Affiliate::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->whereNull('owner_type')
            ->whereNull('owner_id')
            ->where('purpose', ShareTrackingProfile::PURPOSE_SHARE_GUEST)
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        $pruned = 0;
        $skipped = 0;

        foreach ($candidates as $candidate) {
            $deletable = OwnerContext::withOwner(null, fn (): ?Affiliate => DB::transaction(function () use ($candidate, $cutoff, $dryRun): ?Affiliate {
                $affiliate = Affiliate::query()
                    ->withoutGlobalScope(OwnerScope::class)
                    ->whereKey($candidate->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $affiliate instanceof Affiliate
                    || ! ShareTrackingProfile::isMarkedGuest($affiliate)
                    || $affiliate->created_at === null
                    || $affiliate->created_at->greaterThan($cutoff)
                    || ! $this->isAbandonedGuestProfile($affiliate)) {
                    return null;
                }

                if (! $dryRun) {
                    $this->deleteAbandonedGuestProfile($affiliate);
                }

                return $affiliate;
            }, 3));

            $deletable instanceof Affiliate ? $pruned++ : $skipped++;
        }

        return [
            'scanned' => $candidates->count(),
            'pruned' => $pruned,
            'skipped' => $skipped,
        ];
    }

    private function isAbandonedGuestProfile(Affiliate $affiliate): bool
    {
        if (ShareGuestClaim::query()->where('guest_affiliate_id', $affiliate->getKey())->exists()) {
            return false;
        }

        if (AffiliateConversion::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->where('affiliate_id', $affiliate->getKey())
            ->exists()) {
            return false;
        }

        if ($affiliate->fraudSignals()->exists()) {
            return false;
        }

        return ! $this->affiliateHasNonConversionFinancialRecords($affiliate);
    }

    private function deleteAbandonedGuestProfile(Affiliate $affiliate): void
    {
        ShareGuestCredential::query()->where('affiliate_id', $affiliate->getKey())->delete();
        ShareGuestClaimCapability::query()->where('guest_affiliate_id', $affiliate->getKey())->delete();

        $affiliate->links()
            ->get()
            ->each(function (AffiliateLink $link): void {
                $this->detachTrackedLinkForDeletion($link);
                $link->delete();
            });

        $affiliate->attributions()
            ->get()
            ->each(static fn (AffiliateAttribution $attribution): bool => (bool) $attribution->delete());

        AffiliateTouchpoint::query()->where('affiliate_id', $affiliate->getKey())->delete();
        $affiliate->balances()->delete();
        $affiliate->delete();
    }

    public function findAffiliateForUser(User $user): ?Affiliate
    {
        // Marker plus owner association. An unmarked commercial profile is
        // never adopted, and code/name/description prefixes are never read.
        return Affiliate::query()
            ->where('owner_type', $user->getMorphClass())
            ->where('owner_id', $user->getKey())
            ->get()
            ->first(fn (Affiliate $affiliate): bool => ShareTrackingProfile::isMarkedMemberFor($affiliate, $user));
    }

    /**
     * Canonical member affiliate for a guest-claim recipient.
     *
     * Always distinct from the guest source profile (owner-bound member
     * vs ownerless guest). Throws when the existing profile is suspended
     * or an identity conflict cannot resolve, so the claim fails honestly
     * instead of adopting a foreign profile.
     */
    public function ensureClaimRecipientAffiliate(User $claimant): Affiliate
    {
        return OwnerContext::withOwner($claimant, fn (): Affiliate => $this->ensureAffiliateForUser($claimant));
    }

    public function presentedClaimCapability(Request $request): ?string
    {
        $header = (string) config('dawah-share.claim_capability.header', 'X-Dawah-Claim-Capability');
        $input = (string) config('dawah-share.claim_capability.input', 'guest_claim_capability');
        $cookie = (string) config('dawah-share.claim_cookie.name', 'mi_dawah_guest_claim');

        // Private transports only: dedicated header, encrypted cookie, or an
        // explicitly validated non-cacheable POST body field. GET query input
        // is never read, so secrets in shared URLs can never prove ownership.
        $candidates = [
            $request->headers->get($header),
            $request->cookies->get($cookie),
        ];

        if (! $request->isMethodCacheable() && $input !== '') {
            $candidates[] = $request->post($input) ?? $request->json($input);
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/^[A-Za-z0-9]{'.self::CLAIM_CAPABILITY_LENGTH.'}$/', trim($candidate)) === 1) {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * Whether the current browser can be offered an explicit guest claim.
     *
     * True only when the browser presents a usable claim capability for an
     * unclaimed guest profile. Read-only: prompting never writes, and the
     * claim itself still needs authenticated explicit consent.
     */
    public function claimPromptAvailable(User $user, Request $request): bool
    {
        return $this->claimPageState($user, $request)['state'] === 'available';
    }

    /**
     * Explicit claim surface state for an authenticated user and browser.
     *
     * Available only with a usable presented capability for an unclaimed
     * guest profile. Claimed-by-you replays the claimant's own success;
     * every other history (claimed by someone else, revoked, expired, or
     * never issued) renders the same generic unavailable state so the
     * browser learns nothing about which valid history another account
     * owns. Read-only: no state here writes anything.
     *
     * @return array{state: string}
     */
    public function claimPageState(User $user, Request $request): array
    {
        $presented = $this->presentedClaimCapability($request);

        if (! is_string($presented)) {
            return ['state' => 'unavailable'];
        }

        $capability = ShareGuestClaimCapability::query()
            ->where('capability_verifier', ShareGuestClaimCapability::verifierFor($presented))
            ->first();

        if (! $capability instanceof ShareGuestClaimCapability) {
            return ['state' => 'unavailable'];
        }

        $claim = ShareGuestClaim::query()
            ->where('guest_affiliate_id', $capability->guest_affiliate_id)
            ->first();

        if ($claim instanceof ShareGuestClaim) {
            $mine = $claim->isActive()
                && (string) $claim->claimant_user_id === (string) $user->getKey();

            return ['state' => $mine ? 'claimed_by_you' : 'unavailable'];
        }

        if (! $capability->isUsable()) {
            return ['state' => 'unavailable'];
        }

        $guestAffiliate = Affiliate::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->whereKey($capability->guest_affiliate_id)
            ->first();

        if (! ShareTrackingProfile::isMarkedGuest($guestAffiliate)) {
            return ['state' => 'unavailable'];
        }

        return ['state' => 'available'];
    }

    /**
     * Canonical emitted share URL for a link: the app short URL over the
     * single links-managed tracked row. Falls back to a safe direct
     * attributed URL only when the managed row is missing, so generation
     * failure never produces a dead link.
     */
    public function shortUrlForLink(AffiliateLink $link): string
    {
        $tracked = $link->relationLoaded('trackedLink')
            ? $link->trackedLink
            : $link->trackedLink()->first();

        if ($tracked instanceof Link && is_string($tracked->slug) && $tracked->slug !== '' && $tracked->slug_prefix === null) {
            return route('share.short', ['slug' => $tracked->slug]);
        }

        return $this->directAttributedUrlForLink($link);
    }

    private function affiliateHasNonConversionFinancialRecords(Affiliate $affiliate): bool
    {
        if ($affiliate->payouts()->exists()
            || $affiliate->payoutMethods()->exists()
            || $affiliate->payoutHolds()->exists()) {
            return true;
        }

        return $affiliate->balances()
            ->where(function ($query): void {
                $query->where('holding_minor', '!=', 0)
                    ->orWhere('available_minor', '!=', 0)
                    ->orWhere('lifetime_earnings_minor', '!=', 0);
            })
            ->exists();
    }

    /**
     * @return Builder<AffiliateConversion>
     */
    private function monetaryConversionsQuery(Affiliate $affiliate): Builder
    {
        // Conversion-level obligations: payout-linked rows, any nonzero
        // money/held counters (including signed reversal legs), and
        // paid/reversed settlement statuses. A zero accounting projection
        // never makes settled money history disposable.
        return AffiliateConversion::query()
            ->where('affiliate_id', $affiliate->id)
            ->where(function ($query): void {
                $query->whereNotNull('affiliate_payout_id')
                    ->orWhere('commission_minor', '!=', 0)
                    ->orWhere('held_minor', '!=', 0)
                    ->orWhere('value_minor', '!=', 0)
                    ->orWhere('subtotal_minor', '!=', 0)
                    ->orWhereIn('status', ShareTrackingFinance::settledStatusValues());
            });
    }

    /**
     * @return list<string>
     */
    private function monetaryConversionIds(Affiliate $affiliate): array
    {
        return $this->monetaryConversionsQuery($affiliate)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    /**
     * @param  list<string>  $conversionIds
     * @return list<string>
     */
    private function preservedConversionReferenceIds(Affiliate $affiliate, array $conversionIds, string $column): array
    {
        if ($conversionIds === []) {
            return [];
        }

        return AffiliateConversion::query()
            ->where('affiliate_id', $affiliate->id)
            ->whereIn('id', $conversionIds)
            ->whereNotNull($column)
            ->pluck($column)
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $linkIds
     * @param  list<string>  $attributionIds
     */
    private function deleteUnreferencedTouchpoints(Affiliate $affiliate, array $linkIds, array $attributionIds): void
    {
        $touchpoints = AffiliateTouchpoint::query()->where('affiliate_id', $affiliate->id);

        if ($linkIds !== [] || $attributionIds !== []) {
            // NOT IN never matches NULL, so null references are deleted
            // explicitly while preserved references are kept as evidence.
            $touchpoints->where(function ($query) use ($linkIds, $attributionIds): void {
                $query->where(function ($subquery) use ($attributionIds): void {
                    $subquery->whereNull('affiliate_attribution_id');

                    if ($attributionIds !== []) {
                        $subquery->orWhereNotIn('affiliate_attribution_id', $attributionIds);
                    }
                })->where(function ($subquery) use ($linkIds): void {
                    $subquery->whereNull('affiliate_link_id');

                    if ($linkIds !== []) {
                        $subquery->orWhereNotIn('affiliate_link_id', $linkIds);
                    }
                });
            });
        }

        $touchpoints->delete();
    }

    /**
     * @param  list<string>  $linkIds
     * @param  list<string>  $attributionIds
     */
    private function disarmPreservedShareFacts(Affiliate $affiliate, array $linkIds, array $attributionIds): void
    {
        if ($linkIds !== []) {
            AffiliateLink::query()
                ->where('affiliate_id', $affiliate->id)
                ->whereIn('id', $linkIds)
                ->whereNull('deactivated_at')
                ->update(['deactivated_at' => now()]);
        }

        if ($attributionIds !== []) {
            AffiliateAttribution::query()
                ->where('affiliate_id', $affiliate->id)
                ->whereIn('id', $attributionIds)
                ->where(function ($query): void {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->update(['expires_at' => now()]);
        }
    }

    private function revokeGuestCredentials(Affiliate $affiliate): void
    {
        $credentials = ShareGuestCredential::query()
            ->where('affiliate_id', $affiliate->id)
            ->where('status', ShareGuestCredentialStatus::Active->value)
            ->get();

        foreach ($credentials as $credential) {
            $credential->revoke();
        }
    }

    private function revokeGuestClaimsForClaimant(User $user): void
    {
        // Claim rows are preserved as permanent revoked ownership records:
        // deleted claim history can never be stolen or reassigned, and a
        // later restore reactivates nothing.
        ShareGuestClaim::query()
            ->where('claimant_user_id', $user->getKey())
            ->where('status', ShareGuestClaimStatus::Active->value)
            ->get()
            ->each(static fn (ShareGuestClaim $claim): bool => $claim->revoke());
    }

    private function disarmClaimedGuestSources(User $user): void
    {
        // The proven claimant is gone: claimed guest sources keep every
        // accrued provenance and settlement row, but no new credit can
        // accrue on them. Residual historical credentials are revoked
        // (normally already sealed at claim time) and live share links are
        // disabled, so published short URLs open the clean public
        // destination with credit cleared. Nothing is deleted, no award or
        // notification is replayed, and no secret is restored.
        $guestIds = ShareGuestClaim::query()
            ->where('claimant_user_id', $user->getKey())
            ->pluck('guest_affiliate_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        if ($guestIds === []) {
            return;
        }

        ShareGuestCredential::query()
            ->whereIn('affiliate_id', $guestIds)
            ->where('status', ShareGuestCredentialStatus::Active->value)
            ->get()
            ->each(static fn (ShareGuestCredential $credential): bool => $credential->revoke());

        AffiliateLink::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->whereIn('affiliate_id', $guestIds)
            ->whereNull('deactivated_at')
            ->update(['deactivated_at' => now()]);
    }

    private function detachTrackedLinkForDeletion(AffiliateLink $link): void
    {
        $tracked = $link->trackedLink()->first();

        if (! $tracked instanceof Link) {
            return;
        }

        // Canonical minimal public redirect: the links-managed row survives
        // its share context with slug + destination intact, name and baked
        // parameters scrubbed, and the subject repointed at the nil UUID.
        // The nil subject stops the vendor delete cascade (the morph no
        // longer matches this link) while still marking the row as a
        // detached share redirect for the app adapter; a null subject would
        // be indistinguishable from a commercial link. The app short route
        // keeps serving the public destination with zero credit and no
        // private identity; the generic /go/ route keeps its commercial
        // lifecycle semantics (gone for a detached row) untouched.
        $tracked->update([
            'name' => 'Detached share redirect',
            'parameters' => [],
            'subject_id' => '00000000-0000-0000-0000-000000000000',
        ]);
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

    private function createOrReuseAffiliateLinkForAffiliate(Affiliate $affiliate, string $url, ?string $fallbackTitle = null, ?string $origin = null, ?string $guestCredential = null): AffiliateLink
    {
        if (! ShareTrackingProfile::isEligibleForNewCredit($affiliate)) {
            throw new InvalidArgumentException('This share profile is not eligible to create new tracked links.');
        }

        $target = $this->shareTrackingUrlService->classifyShareTarget($url, $fallbackTitle);
        $resolvedOrigin = $this->resolveShareOrigin($origin);

        // One canonical share-link creation per affiliate: the lookup and
        // the creation run inside one transaction serialized on the
        // persisted affiliate row, so two overlapping requests for the same
        // affiliate/destination/origin converge on one AffiliateLink and one
        // managed short URL instead of minting duplicates. The retry loop
        // wraps whole transactions (never retries inside one): a unique
        // token collision aborts its PostgreSQL transaction, and the next
        // attempt re-locks, re-reads, and mints a fresh token. No global
        // uniqueness rule is imposed on legitimate commercial campaigns.
        // A sealed guest identity is never retried here: the caller issues
        // a truthful fresh identity instead.
        $attempts = 0;

        do {
            $attempts++;

            try {
                return DB::transaction(fn (): AffiliateLink => $this->createOrReuseAffiliateLinkInTransaction(
                    $affiliate,
                    $target,
                    $resolvedOrigin,
                    $guestCredential,
                ));
            } catch (QueryException $exception) {
                if (! $this->isUniqueViolation($exception) || $attempts >= 3) {
                    throw $exception;
                }
            }
        } while (true);
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function createOrReuseAffiliateLinkInTransaction(Affiliate $affiliate, array $target, string $resolvedOrigin, ?string $guestCredential = null): AffiliateLink
    {
        // Serialize per-affiliate creation on the persisted profile row and
        // re-read eligibility under that lock: a revocation racing an
        // issuance can never mint one extra link.
        $lockedAffiliate = Affiliate::query()->whereKey($affiliate->getKey())->lockForUpdate()->first();

        if (! $lockedAffiliate instanceof Affiliate || ! ShareTrackingProfile::isEligibleForNewCredit($lockedAffiliate)) {
            throw new InvalidArgumentException('This share profile is not eligible to create new tracked links.');
        }

        if (is_string($guestCredential)) {
            $this->revalidateGuestCredentialSeal($lockedAffiliate, $guestCredential);
        }

        // tracking_url is links-managed (vendor short URL); reuse matches on
        // the persisted destination page URL instead.
        $link = AffiliateLink::query()
            ->where('affiliate_id', $lockedAffiliate->getKey())
            ->where('destination_url', $target['destination_url'])
            ->where('origin', $resolvedOrigin)
            ->lockForUpdate()
            ->first();

        if ($link instanceof AffiliateLink) {
            // A disabled link is never reactivated and its obsolete token is
            // never replaced. The operator decision stands; sharing this
            // destination fails closed with an honest error.
            if (! $link->isEnabled()) {
                throw new InvalidArgumentException('This share link has been disabled. Ask an administrator to review it before sharing again.');
            }

            // The public share token is server-issued at creation through
            // the sanctioned package seam. A row without one predates issued
            // tokens; it is never silently healed or emitted.
            if (! is_string($link->custom_slug) || $link->custom_slug === '') {
                throw new InvalidArgumentException('This share link predates issued share tokens and cannot be shared. Ask an administrator to review it before sharing again.');
            }

            $link->fill([
                'destination_url' => $target['destination_url'],
                'subject_type' => $target['subject_type'],
                'subject_key' => $target['subject_key'],
                'subject_id' => $target['subject_id'],
                'subject_instance' => 'default',
                'origin' => $resolvedOrigin,
                'subject_title_snapshot' => Str::limit((string) ($target['title_snapshot'] ?? ''), 200, ''),
                'subject_metadata' => $target['metadata'] ?? null,
            ]);

            if ($link->isDirty()) {
                $link->save();
            } else {
                $link->touch();
            }

            $this->syncTrackedDestination($link);

            return $link;
        }

        // tracking_url stays links-managed (prohibited input); the opaque
        // public share token travels as custom_slug through the sanctioned
        // atomic package seam, so the link and its managed short URL are
        // created in one transaction with no post-create update window.
        return $this->createTrackingLink->handle(
            affiliate: $lockedAffiliate,
            destinationUrl: (string) $target['destination_url'],
            attributes: [
                'subject_type' => $target['subject_type'] ?? null,
                'subject_key' => $target['subject_key'] ?? null,
                'subject_id' => $target['subject_id'] ?? null,
                'subject_instance' => 'default',
                'origin' => $resolvedOrigin,
                'subject_title_snapshot' => Str::limit((string) ($target['title_snapshot'] ?? ''), 200, ''),
                'subject_metadata' => $target['metadata'] ?? null,
                'deactivated_at' => null,
                'custom_slug' => $this->generateShareToken(),
            ],
        );
    }

    /**
     * Keep the single links-managed tracked row pointed at the current
     * app-owned destination. Reuse may refresh the destination page URL;
     * the managed short URL must follow it as the one redirect authority.
     */
    private function syncTrackedDestination(AffiliateLink $link): void
    {
        $tracked = $link->trackedLink()->first();

        if ($tracked instanceof Link && $tracked->destination_url !== $link->destination_url) {
            $tracked->update(['destination_url' => $link->destination_url]);
        }
    }

    private function ensureAffiliateForUser(User $user): Affiliate
    {
        return DB::transaction(function () use ($user): Affiliate {
            // Serialize canonical lookup+creation on the persisted User row
            // so concurrent creators for one account elect one identity.
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $affiliate = $this->findAffiliateForUser($user);

            if ($affiliate instanceof Affiliate) {
                // A marked but suspended or disabled profile is never
                // reactivated through share creation.
                if (! ShareTrackingProfile::isEligibleForNewCredit($affiliate)) {
                    throw new ShareProfileNotEligibleException('This share profile is not eligible to create new tracked links.');
                }

                $this->refreshMarkedMemberSnapshot($affiliate, $user);

                return $affiliate;
            }

            return $this->createCanonicalAffiliateForUser($user);
        });
    }

    private function refreshMarkedMemberSnapshot(Affiliate $affiliate, User $user): void
    {
        // Reuse refreshes the display snapshot only. Purpose, status and
        // unrelated metadata keys are validated and preserved; no public
        // caller can promote or demote the profile purpose.
        $metadata = is_array($affiliate->metadata) ? $affiliate->metadata : [];
        $metadata[ShareTrackingProfile::MARKER_KEY] = true;
        $metadata[ShareTrackingProfile::KIND_KEY] = ShareTrackingProfile::KIND_MEMBER;

        $affiliate->fill([
            'name' => $user->name,
            'contact_email' => $user->email,
            'metadata' => $metadata,
            'purpose' => ShareTrackingProfile::PURPOSE_SHARE_MEMBER,
        ]);
        $affiliate->save();
    }

    private function createCanonicalAffiliateForUser(User $user): Affiliate
    {
        try {
            // Nested savepoint: a unique collision rolls back to the
            // savepoint only, so the caller's outer transaction stays
            // usable for the requery on real drivers.
            return DB::transaction(function () use ($user): Affiliate {
                $affiliate = new Affiliate([
                    'code' => $this->memberAffiliateCode($user),
                    'name' => $user->name,
                    'description' => 'ilmu360 share-tracking affiliate profile',
                    'status' => Active::class,
                    'commission_type' => CommissionType::Percentage,
                    'commission_rate' => 0,
                    'currency' => (string) config('affiliates.currency.default', 'MYR'),
                    'contact_email' => $user->email,
                    'metadata' => ShareTrackingProfile::memberMetadata(),
                    'purpose' => ShareTrackingProfile::PURPOSE_SHARE_MEMBER,
                    'activated_at' => now(),
                ]);

                $affiliate->assignOwner($user);
                $affiliate->save();

                return $affiliate;
            }, 1);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            // A racing creator won the canonical identity: replay it after
            // rechecking eligibility. Anything else fails explicitly; an
            // unmarked commercial collision is never adopted or suffixed.
            $winner = $this->findAffiliateForUser($user);

            if (! $winner instanceof Affiliate) {
                throw new InvalidArgumentException('A share profile for this account conflicts with an existing profile identity.');
            }

            if (! ShareTrackingProfile::isEligibleForNewCredit($winner)) {
                throw new InvalidArgumentException('This share profile is not eligible to create new tracked links.');
            }

            return $winner;
        }
    }

    private function memberAffiliateCode(User $user): string
    {
        return 'MI'.Str::upper(substr(hash('sha256', 'dawah-share-member-v1|'.$user->getMorphClass().'|'.$user->getKey()), 0, 12));
    }

    /**
     * @return array{AffiliateLink, string|null, string|null} The link, a newly
     *                                                        issued guest credential, and a newly issued claim capability, when
     *                                                        this request minted a fresh guest identity.
     */
    private function createOrReuseGuestAffiliateLink(Request $request, string $url, ?string $fallbackTitle = null, ?string $origin = null): array
    {
        [$affiliate, $issuedCredential, $issuedClaimCapability] = $this->ensureAffiliateForGuest($request);

        if (is_string($issuedCredential)) {
            // Freshly minted identity: create under it directly.
            return [
                $this->createOrReuseAffiliateLinkForAffiliate(
                    $affiliate,
                    $url,
                    $fallbackTitle,
                    $origin,
                ),
                $issuedCredential,
                $issuedClaimCapability,
            ];
        }

        // Reused identity: the credential read above predates the creation
        // locks, so a claim may seal it while this request waits. The seal
        // is revalidated under the creation locks; a sealed identity
        // receives a truthful fresh anonymous identity (with its own new
        // credential and capability transport) instead of authoring a
        // post-seal link into the already owned history.
        $presentedCredential = $this->presentedGuestCredential($request);

        try {
            return [
                $this->createOrReuseAffiliateLinkForAffiliate(
                    $affiliate,
                    $url,
                    $fallbackTitle,
                    $origin,
                    $presentedCredential,
                ),
                null,
                null,
            ];
        } catch (SealedGuestIdentityException) {
            [$freshAffiliate, $freshCredential, $freshCapability] = $this->issueGuestAffiliate();

            return [
                $this->createOrReuseAffiliateLinkForAffiliate(
                    $freshAffiliate,
                    $url,
                    $fallbackTitle,
                    $origin,
                ),
                $freshCredential,
                $freshCapability,
            ];
        }
    }

    /**
     * Revalidate the guest write authorization under the creation locks.
     *
     * The caller already holds the affiliate row lock. The credential row
     * is locked next — the same affiliate-then-credential acquisition
     * order the claim action uses when it seals history — and the claim
     * seal is rechecked, so a creator that read a live credential just
     * before a claim cannot add a link to the owned history after the
     * claim commits.
     *
     * @throws SealedGuestIdentityException
     */
    private function revalidateGuestCredentialSeal(Affiliate $lockedAffiliate, string $guestCredential): void
    {
        $record = ShareGuestCredential::query()
            ->where('credential_verifier', $this->guestCredentialVerifier($guestCredential))
            ->lockForUpdate()
            ->first();

        $sealed = ! $record instanceof ShareGuestCredential
            || $record->status !== ShareGuestCredentialStatus::Active
            || $record->revoked_at !== null
            || $record->expires_at === null
            || ! $record->expires_at->isFuture()
            || (string) $record->affiliate_id !== (string) $lockedAffiliate->getKey();

        if (! $sealed) {
            // Any claim row — active or revoked — permanently seals its
            // history: revoked history (including the claimant's own
            // deleted history) never accepts new authorship.
            $sealed = ShareGuestClaim::query()
                ->where('guest_affiliate_id', $lockedAffiliate->getKey())
                ->exists();
        }

        if ($sealed) {
            throw new SealedGuestIdentityException('The presented guest identity was sealed by a claim.');
        }
    }

    /**
     * @return array{Affiliate, string|null, string|null}
     */
    private function ensureAffiliateForGuest(Request $request): array
    {
        $presentedCredential = $this->presentedGuestCredential($request);

        if (is_string($presentedCredential)) {
            $affiliate = $this->findGuestAffiliateForCredential($presentedCredential);

            if ($affiliate instanceof Affiliate) {
                if (! ShareTrackingProfile::isEligibleForNewCredit($affiliate)) {
                    throw new InvalidArgumentException('This share profile is not eligible to create new tracked links.');
                }

                // Reuse never re-issues the claim capability: a lost
                // capability is permanently unverifiable, and the reusable
                // share credential must never mint fresh ownership proof.
                return [$affiliate, null, null];
            }
        }

        return $this->issueGuestAffiliate();
    }

    /**
     * @return array{Affiliate, string, string}
     */
    private function issueGuestAffiliate(): array
    {
        $attempts = 0;

        do {
            $attempts++;
            $credential = Str::random(self::GUEST_CREDENTIAL_LENGTH);
            $verifier = $this->guestCredentialVerifier($credential);
            $claimCapability = Str::random(self::CLAIM_CAPABILITY_LENGTH);
            $claimVerifier = ShareGuestClaimCapability::verifierFor($claimCapability);

            try {
                return DB::transaction(function () use ($credential, $verifier, $claimCapability, $claimVerifier): array {
                    $affiliate = OwnerContext::withOwner(null, fn (): Affiliate => Affiliate::query()->create([
                        'code' => 'MG'.Str::upper(substr($verifier, 0, 12)),
                        'name' => 'Anonymous Share Profile',
                        'description' => 'ilmu360 anonymous share-tracking profile',
                        'status' => Active::class,
                        'commission_type' => CommissionType::Percentage,
                        'commission_rate' => 0,
                        'currency' => (string) config('affiliates.currency.default', 'MYR'),
                        'metadata' => ShareTrackingProfile::guestMetadata(),
                        'purpose' => ShareTrackingProfile::PURPOSE_SHARE_GUEST,
                        'activated_at' => now(),
                    ]));

                    ShareGuestCredential::query()->create([
                        'affiliate_id' => $affiliate->id,
                        'credential_verifier' => $verifier,
                        'status' => ShareGuestCredentialStatus::Active,
                        'issued_at' => now(),
                        'expires_at' => now()->addDays((int) config('dawah-share.guest_credential.ttl_days', 30)),
                    ]);

                    // Separate single-purpose claim capability: the only
                    // ownership proof a future authenticated claim accepts.
                    ShareGuestClaimCapability::query()->create([
                        'guest_affiliate_id' => $affiliate->id,
                        'capability_verifier' => $claimVerifier,
                        'status' => ShareGuestClaimCapabilityStatus::Active,
                        'issued_at' => now(),
                        'expires_at' => now()->addDays((int) config('dawah-share.claim_capability.ttl_days', 30)),
                    ]);

                    $this->queueGuestCredentialCookie($credential);
                    $this->queueClaimCapabilityCookie($claimCapability);

                    return [$affiliate, $credential, $claimCapability];
                });
            } catch (QueryException $exception) {
                if (! $this->isUniqueViolation($exception) || $attempts >= 3) {
                    throw $exception;
                }
            }
        } while (true);
    }

    private function findGuestAffiliateForCredential(string $credential): ?Affiliate
    {
        $record = ShareGuestCredential::query()
            ->where('credential_verifier', $this->guestCredentialVerifier($credential))
            ->where('status', ShareGuestCredentialStatus::Active->value)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $record instanceof ShareGuestCredential) {
            return null;
        }

        $affiliate = Affiliate::query()->whereKey($record->affiliate_id)->first();

        if (! ShareTrackingProfile::isMarkedGuest($affiliate)) {
            return null;
        }

        $record->forceFill(['last_used_at' => now()])->save();

        return $affiliate;
    }

    private function presentedGuestCredential(Request $request): ?string
    {
        $header = (string) config('dawah-share.guest_credential.header', 'X-Dawah-Guest-Credential');
        $input = (string) config('dawah-share.guest_credential.input', 'guest_credential');
        $cookie = (string) config('dawah-share.guest_cookie.name', 'mi_dawah_guest');

        // Private transports only: dedicated header, encrypted cookie, or an
        // explicitly validated non-cacheable POST body field. GET query input
        // is never read, so secrets in shared URLs can never prove ownership.
        $candidates = [
            $request->headers->get($header),
            $request->cookies->get($cookie),
        ];

        if (! $request->isMethodCacheable() && $input !== '') {
            $candidates[] = $request->post($input) ?? $request->json($input);
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/^[A-Za-z0-9]{'.self::GUEST_CREDENTIAL_LENGTH.'}$/', trim($candidate)) === 1) {
                return trim($candidate);
            }
        }

        return null;
    }

    private function guestCredentialVerifier(string $credential): string
    {
        return hash('sha256', 'dawah-guest-credential-v1|'.$credential);
    }

    private function queueGuestCredentialCookie(string $credential): void
    {
        Cookie::queue(Cookie::make(
            name: (string) config('dawah-share.guest_cookie.name', 'mi_dawah_guest'),
            value: $credential,
            minutes: (int) config('dawah-share.guest_cookie.minutes', 60 * 24 * 30),
            path: (string) config('dawah-share.guest_cookie.path', '/'),
            domain: config('dawah-share.guest_cookie.domain'),
            secure: (bool) (config('dawah-share.guest_cookie.secure') ?? config('session.secure_cookie', false)),
            httpOnly: (bool) config('dawah-share.guest_cookie.http_only', true),
            raw: false,
            sameSite: (string) config('dawah-share.guest_cookie.same_site', 'lax'),
        ));
    }

    private function queueClaimCapabilityCookie(string $capability): void
    {
        Cookie::queue(Cookie::make(
            name: (string) config('dawah-share.claim_cookie.name', 'mi_dawah_guest_claim'),
            value: $capability,
            minutes: (int) config('dawah-share.claim_cookie.minutes', 60 * 24 * 30),
            path: (string) config('dawah-share.claim_cookie.path', '/'),
            domain: config('dawah-share.claim_cookie.domain'),
            secure: (bool) (config('dawah-share.claim_cookie.secure') ?? config('session.secure_cookie', false)),
            httpOnly: (bool) config('dawah-share.claim_cookie.http_only', true),
            raw: false,
            sameSite: (string) config('dawah-share.claim_cookie.same_site', 'lax'),
        ));
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? $exception->getCode()), ['23000', '23505'], true);
    }

    private function directAttributedUrlForLink(AffiliateLink $link): string
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
    private function buildTrackedSharePayload(AffiliateLink $link, string $shareText, ?string $fallbackTitle = null, ?string $issuedGuestCredential = null, ?string $issuedClaimCapability = null): array
    {
        $shareUrl = $this->shortUrlForLink($link);
        $channelUrls = $this->shareTrackingUrlService->channelUrls($shareUrl);

        $payload = [
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

        if (is_string($issuedGuestCredential) && $issuedGuestCredential !== '') {
            $payload['guest_credential'] = $issuedGuestCredential;
        }

        if (is_string($issuedClaimCapability) && $issuedClaimCapability !== '') {
            $payload['guest_claim_capability'] = $issuedClaimCapability;
        }

        return $payload;
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

        $link = AffiliateLink::query()->withoutGlobalScope(OwnerScope::class)->where('custom_slug', $normalizedToken)->first();

        if (! $link instanceof AffiliateLink) {
            return null;
        }

        return $this->isEligibleShareLink($link) ? $link : null;
    }

    private function resolveLinkFromTrackingToken(string $trackingToken, ?User $user = null, ?Request $request = null): ?AffiliateLink
    {
        $normalizedToken = $this->normalizeShareToken($trackingToken);

        if (! is_string($normalizedToken)) {
            return null;
        }

        $link = AffiliateLink::query()->withoutGlobalScope(OwnerScope::class)->where('custom_slug', $normalizedToken)->first();

        if (! $link instanceof AffiliateLink || ! $this->isEligibleShareLink($link)) {
            return null;
        }

        $affiliate = $link->relationLoaded('affiliate') ? $link->affiliate : $link->affiliate()->first();

        if ($user instanceof User) {
            if ($affiliate instanceof Affiliate && ShareTrackingProfile::isMarkedMemberFor($affiliate, $user)) {
                return $link;
            }

            // A proven claim extends the claimant's ownership to the guest
            // history they own: recording on a claimed token stays possible
            // after the historical share credential is sealed, while every
            // account without a claim on that history is still refused.
            if ($affiliate instanceof Affiliate
                && ShareTrackingProfile::isMarkedGuest($affiliate)
                && $this->hasActiveGuestClaim($affiliate, $user)) {
                return $link;
            }

            return null;
        }

        if (! $request instanceof Request) {
            return null;
        }

        $presentedCredential = $this->presentedGuestCredential($request);

        if (! is_string($presentedCredential)) {
            return null;
        }

        $guestAffiliate = $this->findGuestAffiliateForCredential($presentedCredential);

        return $guestAffiliate instanceof Affiliate && (string) $guestAffiliate->id === (string) $link->affiliate_id
            ? $link
            : null;
    }

    private function hasActiveGuestClaim(Affiliate $affiliate, User $user): bool
    {
        return ShareGuestClaim::query()
            ->where('guest_affiliate_id', $affiliate->getKey())
            ->where('claimant_user_id', $user->getKey())
            ->where('status', ShareGuestClaimStatus::Active->value)
            ->whereNull('revoked_at')
            ->exists();
    }

    private function isEligibleShareLink(?AffiliateLink $link, ?Affiliate $affiliate = null): bool
    {
        if (! $link instanceof AffiliateLink || ! $link->isEnabled()) {
            return false;
        }

        $affiliate ??= $link->relationLoaded('affiliate') ? $link->affiliate : $link->affiliate()->first();

        return ShareTrackingProfile::isEligibleForNewCredit($affiliate);
    }

    /**
     * Re-fetch the link and its profile under lock inside a write
     * transaction (fixed order: link, then affiliate). Every new credit
     * write uses this instead of trusting a prechecked stale object, so a
     * revocation racing resolution cannot earn one extra visit or outcome.
     */
    private function lockedEligibleShareLink(AffiliateLink|string|null $linkOrId): ?AffiliateLink
    {
        if ($linkOrId === null || $linkOrId === '') {
            return null;
        }

        $lockedLink = AffiliateLink::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->whereKey($linkOrId instanceof AffiliateLink ? $linkOrId->getKey() : $linkOrId)
            ->lockForUpdate()
            ->first();

        if (! $lockedLink instanceof AffiliateLink || ! $lockedLink->isEnabled()) {
            return null;
        }

        $lockedAffiliate = Affiliate::query()->whereKey($lockedLink->affiliate_id)->lockForUpdate()->first();

        if (! ShareTrackingProfile::isEligibleForNewCredit($lockedAffiliate)) {
            return null;
        }

        $lockedLink->setRelation('affiliate', $lockedAffiliate);

        return $lockedLink;
    }

    private function isProvenSelfCredit(Affiliate $affiliate, AffiliateAttribution $attribution, ?Model $actor): bool
    {
        if (! $actor instanceof User) {
            return false;
        }

        $actorId = (string) $actor->getAuthIdentifier();
        $owner = $affiliate->owner;

        if ($owner instanceof User && (string) $owner->getAuthIdentifier() === $actorId) {
            return true;
        }

        $sharerUserId = $this->nullableString($attribution->sharer_user_id);

        if ($sharerUserId !== null && $sharerUserId === $actorId) {
            return true;
        }

        // A proven guest claim re-evaluates historical self credit at
        // outcome time: the actor owns this guest history through an active
        // association. The frozen source attribution itself is never
        // rewritten, and pre-claim unattributed receipts stay frozen.
        if (! ShareTrackingProfile::isMarkedGuest($affiliate)) {
            return false;
        }

        return ShareGuestClaim::query()
            ->where('guest_affiliate_id', $affiliate->getKey())
            ->where('claimant_user_id', $actorId)
            ->where('status', ShareGuestClaimStatus::Active->value)
            ->whereNull('revoked_at')
            ->exists();
    }

    private function generateShareToken(): string
    {
        do {
            $token = Str::lower(Str::random(self::SHARE_TOKEN_LENGTH));
        } while (AffiliateLink::query()->withoutGlobalScope(OwnerScope::class)->where('custom_slug', $token)->exists());

        return $token;
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

    private function lockedBrowserSelection(string $browserKey): ShareBrowserSelection
    {
        $selection = ShareBrowserSelection::query()
            ->where('browser_key', $browserKey)
            ->lockForUpdate()
            ->first();

        if ($selection instanceof ShareBrowserSelection) {
            return $selection;
        }

        try {
            return DB::transaction(static fn (): ShareBrowserSelection => ShareBrowserSelection::query()->create([
                'browser_key' => $browserKey,
                'current_attribution_id' => null,
                'accepted_at' => null,
            ]), 1);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            return ShareBrowserSelection::query()
                ->where('browser_key', $browserKey)
                ->lockForUpdate()
                ->firstOrFail();
        }
    }

    private function lockedExistingBrowserSelection(string $browserKey): ?ShareBrowserSelection
    {
        return ShareBrowserSelection::query()
            ->where('browser_key', $browserKey)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Locked browser winner: the persisted selection is attribution
     * authority, never the transported cookie value. Assumes the
     * selection row is already locked; locks attribution, then link,
     * then affiliate (fixed order: selection, attribution, link,
     * affiliate). Returns null for missing, mismatched, expired, or
     * ineligible winners; the caller clears the selection.
     */
    private function lockedBrowserWinner(ShareBrowserSelection $selection, string $browserKey): ?AffiliateAttribution
    {
        $attributionId = $selection->current_attribution_id;

        if (! is_string($attributionId) || $attributionId === '') {
            return null;
        }

        $locked = AffiliateAttribution::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->whereKey($attributionId)
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof AffiliateAttribution) {
            return null;
        }

        if ((string) $locked->visitor_key !== $browserKey) {
            return null;
        }

        if ((string) $locked->attribution_type !== 'landing') {
            return null;
        }

        $lockedLink = $this->lockedEligibleShareLink($locked->affiliate_link_id);

        if (! $lockedLink instanceof AffiliateLink) {
            return null;
        }

        if ((string) $locked->affiliate_id !== (string) $lockedLink->affiliate_id) {
            return null;
        }

        if ($this->isAttributionExpired($locked)) {
            return null;
        }

        return $locked;
    }

    private function clearBrowserSelection(string $browserKey, ?ShareBrowserSelection $selection = null): void
    {
        $selection ??= ShareBrowserSelection::query()->where('browser_key', $browserKey)->first();

        if ($selection instanceof ShareBrowserSelection) {
            $selection->forceFill([
                'current_attribution_id' => null,
                'accepted_at' => null,
            ])->save();
        }
    }

    private function browserKeyFromRequest(?Request $request): ?string
    {
        if (! $request instanceof Request) {
            return null;
        }

        $cookieState = $this->readCookieState($request);
        $browserKey = $cookieState['visitor_key'] ?? null;

        if (! is_string($browserKey) || $browserKey === '' || mb_strlen($browserKey) > 160 || str_contains($browserKey, "\0")) {
            return null;
        }

        return $browserKey;
    }

    private function assertValidInheritancePairing(DawahShareOutcomeType $childType, string $sourceBusinessKey): void
    {
        $segments = explode(':', $sourceBusinessKey, 3);

        if (count($segments) !== 3) {
            throw new InvalidArgumentException('Source business key must be a canonical namespace:type:source identity.');
        }

        $sourceType = DawahShareOutcomeType::tryFrom($segments[1]);

        if (! $sourceType instanceof DawahShareOutcomeType) {
            throw new InvalidArgumentException('Source business key carries an unknown outcome type.');
        }

        ShareOutcomeBusinessKey::parse($sourceBusinessKey, $sourceType);

        if ($childType === DawahShareOutcomeType::EventCheckin && $sourceType !== DawahShareOutcomeType::EventRegistration) {
            throw new InvalidArgumentException('Event check-in inheritance requires an event registration source.');
        }
    }

    /**
     * Locked source validity for inheritance: receipt, conversion,
     * correction, and fraud evidence serialized with the child freeze.
     * Link deactivation and TTL never invalidate accrued provenance;
     * cancellation, rejection, correction, and confirmed fraud do.
     */
    private function lockedValidInheritanceSource(string $sourceBusinessKey): ?AffiliateAttribution
    {
        $sourceReceipt = ShareSourceAttributionReceipt::query()
            ->where('business_key', $sourceBusinessKey)
            ->lockForUpdate()
            ->first();

        if (! $sourceReceipt instanceof ShareSourceAttributionReceipt
            || ! $sourceReceipt->isAttributed()
            || $sourceReceipt->attribution_id === null) {
            return null;
        }

        $sourceConversion = AffiliateConversion::query()
            ->where('idempotency_key', hash('sha256', $sourceBusinessKey))
            ->lockForUpdate()
            ->first();

        if (! $sourceConversion instanceof AffiliateConversion || $this->isInvalidConversion($sourceConversion)) {
            return null;
        }

        if ((string) $sourceConversion->affiliate_attribution_id !== (string) $sourceReceipt->attribution_id) {
            return null;
        }

        $correction = ShareOutcomeCorrection::query()
            ->where('business_key', $sourceBusinessKey)
            ->lockForUpdate()
            ->first();

        if ($correction instanceof ShareOutcomeCorrection) {
            return null;
        }

        $confirmedFraud = AffiliateFraudSignal::query()
            ->where('conversion_id', $sourceConversion->getKey())
            ->where('status', FraudSignalStatus::Confirmed->value)
            ->lockForUpdate()
            ->exists();

        if ($confirmedFraud) {
            return null;
        }

        $attribution = AffiliateAttribution::query()
            ->withoutGlobalScope(OwnerScope::class)
            ->whereKey($sourceReceipt->attribution_id)
            ->lockForUpdate()
            ->first();

        if (! $attribution instanceof AffiliateAttribution) {
            return null;
        }

        if ((string) $attribution->attribution_type !== 'landing') {
            return null;
        }

        if ((string) $sourceConversion->affiliate_id !== (string) $attribution->affiliate_id) {
            return null;
        }

        return $attribution;
    }

    private function isAttributionExpired(AffiliateAttribution $attribution): bool
    {
        return $attribution->expires_at === null || $attribution->expires_at->lte(now());
    }

    private function dedupeVisitExists(string $browserKey, string $linkId, string $safeUrl): bool
    {
        return AffiliateTouchpoint::query()
            ->where('visitor_key', $browserKey)
            ->where('affiliate_link_id', $linkId)
            ->where('touchpoint_type', 'visit')
            ->where('url', $safeUrl)
            ->where('touched_at', '>=', now()->subMinutes((int) config('dawah-share.visit_dedupe_minutes', 5)))
            ->exists();
    }

    private function isSameExplicitLanding(AffiliateAttribution $current, AffiliateLink $lockedLink, ?string $shareProvider): bool
    {
        if ((string) $current->affiliate_link_id !== (string) $lockedLink->getKey()) {
            return false;
        }

        if ((string) $current->affiliate_id !== (string) $lockedLink->affiliate_id) {
            return false;
        }

        $currentChannel = is_string($current->channel) ? $current->channel : null;

        if ($currentChannel !== $shareProvider) {
            return false;
        }

        return (string) $current->origin === (string) $this->shareOriginForLink($lockedLink);
    }

    private function createLandingAttribution(AffiliateLink $link, Request $request, string $browserKey, ?string $shareProvider): AffiliateAttribution
    {
        $linkMetadata = $this->linkMetadata($link);
        $affiliate = $link->affiliate()->first();

        if (! $affiliate instanceof Affiliate) {
            throw new LogicException('A tracked share link must belong to an affiliate.');
        }

        $attribution = new AffiliateAttribution([
            'affiliate_id' => $link->affiliate_id,
            'affiliate_code' => (string) $affiliate->code,
            'subject_type' => $link->subject_type,
            'subject_key' => (string) ($linkMetadata['subject_key'] ?? $link->subject_key ?? $link->id),
            'subject_id' => $link->subject_id,
            'subject_instance' => 'default',
            'subject_title_snapshot' => $link->subject_title_snapshot,
            'cookie_value' => (string) Str::ulid(),
            'landing_url' => $this->minimalPersistedUrl($request->fullUrl()),
            'referrer_url' => null,
            'user_agent' => null,
            'ip_address' => IpHasher::hash($request->ip()),
            'user_id' => null,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'last_cookie_seen_at' => now(),
            'expires_at' => $this->expiryTimestamp(),
            'affiliate_link_id' => $link->id,
            'attribution_type' => 'landing',
            'visitor_key' => $browserKey,
            'channel' => $shareProvider,
            'origin' => $this->shareOriginForLink($link),
            'sharer_user_id' => $linkMetadata['sharer_user_id'] ?? null,
            'metadata' => $this->sanitizedCampaignMetadata($request),
        ]);

        $attribution->owner_type = $affiliate->owner_type;
        $attribution->owner_id = $affiliate->owner_id;
        $attribution->save();

        return $attribution;
    }

    /**
     * @return array<string, string>|null
     */
    private function sanitizedCampaignMetadata(Request $request): ?array
    {
        $metadata = $this->shareTrackingUrlService->sanitizedCampaignParams($request);

        return $metadata === [] ? null : $metadata;
    }

    private function resolveActiveAffiliateAttribution(?Request $request = null): ?AffiliateAttribution
    {
        $request ??= request();

        if (! $request instanceof Request) {
            return null;
        }

        // Browser authority: the persisted selection is the winner, never
        // the transported cookie value. A stale or reordered cookie can
        // never override the committed selection.
        $browserKey = $this->browserKeyFromRequest($request);

        if ($browserKey === null) {
            return null;
        }

        $selection = ShareBrowserSelection::query()->where('browser_key', $browserKey)->first();

        if (! $selection instanceof ShareBrowserSelection || $selection->current_attribution_id === null) {
            return null;
        }

        $attribution = AffiliateAttribution::query()->withoutGlobalScope(OwnerScope::class)
            ->whereKey($selection->current_attribution_id)
            ->first();

        if (! $attribution instanceof AffiliateAttribution) {
            return null;
        }

        if ((string) $attribution->visitor_key !== $browserKey) {
            return null;
        }

        if ((string) $attribution->attribution_type !== 'landing') {
            return null;
        }

        $link = $attribution->affiliate_link_id !== null
            ? AffiliateLink::query()->withoutGlobalScope('affiliate_owner')->whereKey($attribution->affiliate_link_id)->first()
            : null;

        if (! $link instanceof AffiliateLink) {
            return null;
        }

        $affiliate = Affiliate::query()->withoutGlobalScope(OwnerScope::class)->whereKey($link->affiliate_id)->first();

        if (! $affiliate instanceof Affiliate
            || $attribution->owner_type !== $affiliate->owner_type
            || (string) $attribution->owner_id !== (string) $affiliate->owner_id) {
            return null;
        }

        if (! ShareTrackingProfile::isEligibleForNewCredit($affiliate) || ! $link->isEnabled()) {
            return null;
        }

        if ((string) $attribution->affiliate_id !== (string) $link->affiliate_id) {
            return null;
        }

        if ($this->isAttributionExpired($attribution)) {
            return null;
        }

        return $attribution;
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
            'ip_address' => IpHasher::hash($request->ip()),
            'user_agent' => null,
            'touchpoint_type' => 'visit',
            'metadata' => ['visit_kind' => $kind],
            'affiliate_link_id' => $attribution->affiliate_link_id,
            'visitor_key' => $attribution->visitor_key,
            'channel' => $attribution->channel,
            'origin' => $attribution->origin,
            'url' => $cleanUrl,
            'referrer_url' => null,
            'subject_type' => $subject['subject_type'],
            'subject_key' => $subject['subject_key'],
            'subject_id' => $subject['subject_id'],
            'touched_at' => now(),
        ]);
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
            'ip_address' => IpHasher::hash($request?->ip()),
            'user_agent' => null,
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
            'referrer_url' => null,
            'touched_at' => now()->utc(),
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

    private function mapLink(AffiliateLink $link): ShareTrackingLinkData
    {
        return new ShareTrackingLinkData(
            id: (string) $link->id,
            backend: 'affiliates',
            subjectType: (string) ($link->subject_type ?: 'page'),
            subjectId: $this->nullableString($link->subject_id),
            subjectKey: (string) ($link->subject_key ?: 'page:unknown'),
            destinationUrl: (string) $link->destination_url,
            canonicalUrl: (string) $link->destination_url,
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
            landingUrl: $attribution->landing_url,
            shareProvider: $attribution->channel,
            subjectType: $attribution->subject_type,
            subjectId: $this->nullableString($attribution->subject_id) ?? $this->nullableString($attribution->subject_key),
            subjectKey: $attribution->subject_key,
            titleSnapshot: $attribution->subject_title_snapshot,
            firstSeenAt: $attribution->first_seen_at,
            lastSeenAt: $attribution->last_seen_at,
            expiresAt: $attribution->expires_at,
        );
    }

    private function mapOutcome(AffiliateConversion $conversion): ShareTrackingOutcomeData
    {
        return new ShareTrackingOutcomeData(
            id: (string) $conversion->id,
            backend: 'affiliates',
            linkId: $this->nullableString($conversion->affiliate_link_id) ?? '',
            outcomeType: (string) ($conversion->conversion_type ?: 'unknown'),
            subjectType: $conversion->subject_type,
            subjectId: $this->nullableString($conversion->subject_id),
            subjectKey: $conversion->subject_key,
            linkTitleSnapshot: $this->nullableString($conversion->subject_title_snapshot),
            occurredAt: $conversion->occurred_at,
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

    private function minimalPersistedUrl(string $url): string
    {
        return $this->shareTrackingUrlService->minimalPersistedUrl($url);
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
