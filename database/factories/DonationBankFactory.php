<?php

namespace Database\Factories;

use AIArmada\Addressing\Models\AddressCountry;
use App\Features\DonationChannels\Api\Enums\DonationBankStatus;
use App\Features\DonationChannels\Models\DonationBank;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * @extends Factory<DonationBank>
 */
class DonationBankFactory extends Factory
{
    #[\Override]
    protected $model = DonationBank::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'country_id' => $this->defaultCountryId(...),
            'code' => fn (): string => mb_strtoupper(fake()->unique()->bothify('TST####')),
            'name' => fn (): string => fake()->unique()->company().' Bank',
            'source_url' => fn (): string => 'https://example.com/banks/'.fake()->unique()->slug(2),
            'status' => DonationBankStatus::Active,
        ];
    }

    public function retired(): static
    {
        return $this->state(fn (): array => [
            'status' => DonationBankStatus::Retired,
            'retired_at' => now(),
        ]);
    }

    private function defaultCountryId(): string
    {
        $country = AddressCountry::query()->where('iso2', 'MY')->first()
            ?? AddressCountry::query()->orderBy('name')->first();

        if (! $country instanceof AddressCountry) {
            throw new RuntimeException('DonationBankFactory requires an addressing country; ensure the addressing data is seeded.');
        }

        return (string) $country->getKey();
    }
}
