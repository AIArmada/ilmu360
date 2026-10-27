<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Enums\SignupAttributionSourceType;
use InvalidArgumentException;

final readonly class SignupAttributionSource
{
    public function __construct(
        public SignupAttributionSourceType $type,
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
            'type' => $this->type->value,
            'referrer_user_id' => $this->referrerUserId,
            'affiliate_id' => $this->affiliateId,
            'affiliate_link_id' => $this->affiliateLinkId,
            'affiliate_attribution_id' => $this->affiliateAttributionId,
        ];
    }

    /**
     * Rehydrates a source previously captured in the trusted OAuth transaction store.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? null;
        $sourceType = is_string($type) ? SignupAttributionSourceType::tryFrom($type) : null;

        if (! $sourceType instanceof SignupAttributionSourceType) {
            throw new InvalidArgumentException('The signup attribution snapshot has an invalid source type.');
        }

        return new self(
            type: $sourceType,
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

        throw new InvalidArgumentException('The signup attribution snapshot has an invalid identifier.');
    }
}
