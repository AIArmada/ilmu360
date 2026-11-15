<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api;

/**
 * @phpstan-type ResolvedSelection array{
 *     place_id: string,
 *     address: array<string, mixed>,
 *     display_name: string|null,
 *     inferred_type: string|null,
 *     website_url: string|null,
 *     autofilled_contacts: list<array<string, mixed>>,
 *     autofilled_social_profiles: list<array<string, mixed>>
 * }
 * @phpstan-type MergedSelection array{
 *     state: array<string, mixed>,
 *     autofilled_contacts: list<array<string, mixed>>,
 *     autofilled_social_profiles: list<array<string, mixed>>,
 *     autofilled_name: string|null,
 *     autofilled_type: string|null
 * }
 */
final readonly class VerifiedInstitutionMapsSelection
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(private array $payload) {}

    /**
     * @param  ResolvedSelection  $selection
     * @param  MergedSelection  $merged
     */
    public static function fromResolvedSelection(array $selection, array $merged): self
    {
        $address = $selection['address'];
        unset($address['cascade_reset_guard']);

        return new self([
            ...$selection,
            'address' => $address,
            'autofilled_contacts' => $merged['autofilled_contacts'],
            'autofilled_social_profiles' => $merged['autofilled_social_profiles'],
            'autofilled_name' => $merged['autofilled_name'],
            'autofilled_type' => $merged['autofilled_type'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromCache(array $payload): self
    {
        return new self($payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}
