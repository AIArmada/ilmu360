<?php

declare(strict_types=1);

namespace App\Shared\Auth;

use InvalidArgumentException;

/** Untrusted signup attribution context captured before account creation. */
final readonly class SignupAttributionContext
{
    /**
     * @param  array<string, mixed>|null  $affiliateSource
     */
    public function __construct(
        public ?string $referralInviteId = null,
        public ?array $affiliateSource = null,
    ) {}

    /**
     * @return array{referral_invite_id: ?string, affiliate_source: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'referral_invite_id' => $this->referralInviteId,
            'affiliate_source' => $this->affiliateSource,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $inviteId = $data['referral_invite_id'] ?? null;
        $affiliateSource = $data['affiliate_source'] ?? null;

        if ($inviteId !== null && ! is_string($inviteId)) {
            throw new InvalidArgumentException('The signup attribution context has an invalid referral invite.');
        }

        if ($affiliateSource !== null && ! is_array($affiliateSource)) {
            throw new InvalidArgumentException('The signup attribution context has an invalid affiliate source.');
        }

        if (is_array($affiliateSource)) {
            SignupAttributionSource::fromArray($affiliateSource);
        }

        return new self(
            referralInviteId: $inviteId,
            affiliateSource: $affiliateSource,
        );
    }
}
