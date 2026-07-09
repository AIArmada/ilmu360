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
    public function sharePayload(?User $user, string $url, string $shareText, ?string $fallbackTitle = null, ?string $origin = null, ?Request $request = null): array;

    /**
     * @return array<string, string>
     */
    public function redirectLinks(string $url, string $shareText, ?string $fallbackTitle = null): array;

    public function redirectUrl(
        string $provider,
        ?User $user,
        string $url,
        string $shareText,
        ?string $fallbackTitle = null,
        ?string $origin = null,
        ?Request $request = null,
    ): string;

    public function attributedUrl(User $user, string $url, ?string $fallbackTitle = null, ?string $origin = null): string;

    public function recordShareAction(
        string $provider,
        ?User $user,
        string $trackingToken,
        ?Request $request = null,
    ): void;

    public function captureRequest(Request $request): ?string;

    public function resolveActiveAttribution(?Request $request = null): ?ShareTrackingAttributionData;

    public function recordSignup(User $user, ?Request $request = null): ?ShareTrackingOutcomeData;

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
    ): ?ShareTrackingOutcomeData;

    public function createOrReuseLink(User $user, string $url, ?string $fallbackTitle = null, ?string $origin = null): ShareTrackingLinkData;

    public function deleteUserTracking(User $user): void;
}
