<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\ShareTracking\ShareTrackingAttributionData;
use App\Data\ShareTracking\ShareTrackingLinkData;
use App\Data\ShareTracking\ShareTrackingOutcomeData;
use App\Enums\DawahShareOutcomeType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Null-object share tracking service when affiliate attribution is unavailable.
 */
final class NullShareTrackingService implements ShareTrackingContract
{
    public function supportedProviders(): array
    {
        return [];
    }

    public function supportedChannels(): array
    {
        return [];
    }

    public function supportedOrigins(): array
    {
        return [];
    }

    public function sharePayload(?User $user, string $url, string $shareText, ?string $fallbackTitle = null, ?string $origin = null, ?Request $request = null): array
    {
        return [
            'url' => $url,
            'share_text' => $shareText,
            'title' => $fallbackTitle,
            'origin' => $origin,
            'providers' => [],
            'tracking_token' => null,
        ];
    }

    public function redirectLinks(string $url, string $shareText, ?string $fallbackTitle = null): array
    {
        return [];
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
        return $url;
    }

    public function attributedUrl(User $user, string $url, ?string $fallbackTitle = null, ?string $origin = null): string
    {
        return $url;
    }

    public function recordShareAction(
        string $provider,
        ?User $user,
        string $trackingToken,
        ?Request $request = null,
    ): void {}

    public function captureRequest(Request $request): ?string
    {
        return null;
    }

    public function resolveActiveAttribution(?Request $request = null): ?ShareTrackingAttributionData
    {
        return null;
    }

    public function recordSignup(User $user, ?Request $request = null): ?ShareTrackingOutcomeData
    {
        return null;
    }

    public function recordOutcome(
        DawahShareOutcomeType $type,
        string $outcomeKey,
        ?Model $subject = null,
        ?User $actor = null,
        ?Request $request = null,
        array $metadata = [],
    ): ?ShareTrackingOutcomeData {
        return null;
    }

    public function createOrReuseLink(User $user, string $url, ?string $fallbackTitle = null, ?string $origin = null): ShareTrackingLinkData
    {
        return new ShareTrackingLinkData(
            id: 'null',
            backend: 'null',
            subjectType: 'anonymous',
            subjectId: null,
            subjectKey: 'null',
            destinationUrl: $url,
            canonicalUrl: $url,
            titleSnapshot: $fallbackTitle ?? '',
            lastSharedAt: null,
        );
    }

    public function deleteUserTracking(User $user): void {}
}
