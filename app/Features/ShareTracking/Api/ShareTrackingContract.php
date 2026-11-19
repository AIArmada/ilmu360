<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

use App\Features\ShareTracking\Api\Data\ShareSubject;
use App\Features\ShareTracking\Api\Data\ShareTrackingAttributionData;
use App\Features\ShareTracking\Api\Data\ShareTrackingLinkData;
use App\Features\ShareTracking\Api\Data\ShareTrackingOutcomeData;
use App\Shared\Auth\AccountActor;
use App\Shared\ShareTracking\ShareRequestContext;
use Carbon\CarbonInterface;

interface ShareTrackingContract
{
    /**
     * @return list<string>
     */
    public function supportedProviders(): array;

    /**
     * @return list<string>
     */
    public function supportedChannels(): array;

    /**
     * @return list<string>
     */
    public function supportedOrigins(): array;

    /**
     * @return array<string, mixed>
     */
    public function sharePayload(?AccountActor $user, string $url, string $shareText, ?string $fallbackTitle = null, ?string $origin = null, ?ShareRequestContext $request = null): array;

    /**
     * @return array<string, string>
     */
    public function redirectLinks(string $url, string $shareText, ?string $fallbackTitle = null): array;

    public function redirectUrl(
        string $provider,
        ?AccountActor $user,
        string $url,
        string $shareText,
        ?string $fallbackTitle = null,
        ?string $origin = null,
        ?ShareRequestContext $request = null,
    ): string;

    public function attributedUrl(AccountActor $user, string $url, ?string $fallbackTitle = null, ?string $origin = null): string;

    public function recordShareAction(
        string $provider,
        ?AccountActor $user,
        string $trackingToken,
        ?ShareRequestContext $request = null,
    ): void;

    public function captureRequest(ShareRequestContext $request): ?string;

    public function resolveActiveAttribution(?ShareRequestContext $request = null): ?ShareTrackingAttributionData;

    public function recordSignup(AccountActor $user, ?ShareRequestContext $request = null): ?ShareTrackingOutcomeData;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordOutcome(
        ShareOutcomeType $type,
        string $businessKey,
        ?ShareSubject $subject = null,
        ?AccountActor $actor = null,
        ?ShareRequestContext $request = null,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null,
    ): ?ShareTrackingOutcomeData;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordInheritedOutcome(
        ShareOutcomeType $type,
        string $businessKey,
        string $sourceBusinessKey,
        ?ShareSubject $subject = null,
        ?AccountActor $actor = null,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null,
    ): ?ShareTrackingOutcomeData;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordCheckoutInheritedOutcome(
        ShareOutcomeType $type,
        string $businessKey,
        ?string $persistedAttributionId,
        ?ShareSubject $subject = null,
        ?AccountActor $actor = null,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null,
    ): ?ShareTrackingOutcomeData;

    public function createOrReuseLink(AccountActor $user, string $url, ?string $fallbackTitle = null, ?string $origin = null): ShareTrackingLinkData;

    public function deleteUserTracking(AccountActor $user): void;
}
