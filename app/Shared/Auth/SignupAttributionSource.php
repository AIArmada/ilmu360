<?php

declare(strict_types=1);

namespace App\Shared\Auth;

/** Immutable acquisition source captured for a signup. */
final readonly class SignupAttributionSource
{
    public function __construct(
        public string $type,
        public ?string $referrerUserId = null,
        public ?string $affiliateId = null,
        public ?string $affiliateLinkId = null,
        public ?string $affiliateAttributionId = null,
    ) {}

    /**
     * @return array{type: string, referrer_user_id: ?string, affiliate_id: ?string, affiliate_link_id: ?string, affiliate_attribution_id: ?string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'referrer_user_id' => $this->referrerUserId,
            'affiliate_id' => $this->affiliateId,
            'affiliate_link_id' => $this->affiliateLinkId,
            'affiliate_attribution_id' => $this->affiliateAttributionId,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? null;

        if (! is_string($type) || ! in_array($type, ['direct', 'share_member', 'share_guest', 'commercial_affiliate', 'referral_invite'], true)) {
            throw new \InvalidArgumentException('The signup attribution snapshot has an invalid source type.');
        }

        return new self(
            type: $type,
            referrerUserId: self::optionalString($data, 'referrer_user_id'),
            affiliateId: self::optionalString($data, 'affiliate_id'),
            affiliateLinkId: self::optionalString($data, 'affiliate_link_id'),
            affiliateAttributionId: self::optionalString($data, 'affiliate_attribution_id'),
        );
    }

    /** @param array<string, mixed> $data */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if ($value === null || is_string($value) && $value !== '') {
            return $value;
        }

        throw new \InvalidArgumentException('The signup attribution snapshot has an invalid identifier.');
    }
}
