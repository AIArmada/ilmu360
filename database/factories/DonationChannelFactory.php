<?php

namespace Database\Factories;

use App\Features\DonationChannels\Api\DonationChannelOptions;
use App\Features\DonationChannels\Api\Enums\DonationChannelStatus;
use App\Features\DonationChannels\Models\DonationChannel;
use App\Features\Institutions\Models\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DonationChannel>
 */
class DonationChannelFactory extends Factory
{
    #[\Override]
    protected $model = DonationChannel::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var 'bank_account'|'duitnow'|'ewallet' $method */
        $method = fake()->randomElement(['bank_account', 'duitnow', 'ewallet']);

        $base = [
            'donatable_type' => Institution::class,
            'donatable_id' => Institution::factory(),
            'label' => fake()->optional()->randomElement(['Tabung Masjid', 'Infaq', 'Dana Pembangunan', 'Zakat', 'Sedekah']),
            'recipient' => fake()->name(),
            'method' => $method,
            'status' => fake()->randomElement([DonationChannelStatus::Pending, DonationChannelStatus::Verified]),
            'is_default' => false,
        ];

        return match ($method) {
            'bank_account' => array_merge($base, $this->bankAccountFields()),
            'duitnow' => array_merge($base, $this->duitnowFields()),
            'ewallet' => array_merge($base, $this->ewalletFields()),
            default => array_merge($base, $this->bankAccountFields()),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function bankAccountFields(): array
    {
        /** @var array{0: string, 1: string} $bank */
        $bank = fake()->randomElement([
            ['MB2U0227', 'Malayan Banking Berhad'],
            ['BCBB0235', 'CIMB Bank Berhad'],
            ['BIMB0340', 'Bank Islam Malaysia Berhad'],
            ['RHB0218', 'RHB Bank Berhad'],
            ['PBB0233', 'Public Bank Berhad'],
            ['BKRM0602', 'Bank Kerjasama Rakyat Malaysia Berhad (Bank Rakyat)'],
        ]);

        return [
            'currency' => 'MYR',
            'bank_code' => $bank[0],
            'bank_name' => $bank[1],
            'account_number' => fake()->numerify('############'),
            'iban' => null,
            'swift_bic' => null,
            // Ensure other method fields are null
            'duitnow_type' => null,
            'duitnow_value' => null,
            'ewallet_provider' => null,
            'ewallet_handle' => null,
            'ewallet_qr_payload' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function duitnowFields(): array
    {
        $type = fake()->randomElement(array_keys(DonationChannelOptions::DUITNOW_ID_TYPES));

        return [
            'currency' => 'MYR',
            'duitnow_type' => $type,
            'duitnow_value' => match ($type) {
                'mobile' => fake()->numerify('01#-#######'),
                default => fake()->numerify('########'),
            },
            // Ensure other method fields are null
            'bank_code' => null,
            'bank_name' => null,
            'account_number' => null,
            'iban' => null,
            'swift_bic' => null,
            'routing_code' => null,
            'ewallet_provider' => null,
            'ewallet_handle' => null,
            'ewallet_qr_payload' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function ewalletFields(): array
    {
        $provider = fake()->randomElement(array_keys(DonationChannelOptions::EWALLET_PROVIDERS));

        return [
            'currency' => 'MYR',
            'ewallet_provider' => $provider,
            'ewallet_handle' => fake()->numerify('01#-#######'),
            'ewallet_qr_payload' => null,
            // Ensure other method fields are null
            'bank_code' => null,
            'bank_name' => null,
            'account_number' => null,
            'iban' => null,
            'swift_bic' => null,
            'routing_code' => null,
            'duitnow_type' => null,
            'duitnow_value' => null,
        ];
    }

    /**
     * State for bank account method.
     */
    public function bankAccount(): static
    {
        return $this->state(fn (array $attributes) => array_merge(
            ['method' => 'bank_account'],
            $this->bankAccountFields()
        ));
    }

    /**
     * State for DuitNow method.
     */
    public function duitnow(): static
    {
        return $this->state(fn (array $attributes) => array_merge(
            ['method' => 'duitnow'],
            $this->duitnowFields()
        ));
    }

    /**
     * State for e-wallet method.
     */
    public function ewallet(): static
    {
        return $this->state(fn (array $attributes) => array_merge(
            ['method' => 'ewallet'],
            $this->ewalletFields()
        ));
    }

    /**
     * State for verified status.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DonationChannelStatus::Verified,
            'verified_at' => now(),
        ]);
    }

    /**
     * State for default channel.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }
}
