<?php

namespace Database\Factories;

use App\Models\RewardPointOperation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RewardPointOperation>
 */
class RewardPointOperationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'kind' => 'reserve',
            'operation_key' => 'rsv:test:'.Str::lower(Str::random(8)),
            'recipient_type' => 'user',
            'recipient_id' => Str::uuid(),
            'family_key' => 'family-'.Str::lower(Str::random(6)),
            'unit' => 'pts',
            'points' => 10,
            'reservation_key' => null,
            'linked_aggregate_key' => null,
        ];
    }
}
