<?php

namespace Database\Factories;

use App\Enums\Rewards\RewardPointMovementKind;
use App\Models\RewardPointMovement;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RewardPointMovement>
 */
class RewardPointMovementFactory extends Factory
{
    public function definition(): array
    {
        $key = 'op:'.Str::lower(Str::random(10));

        return [
            'lot_id' => Str::uuid(),
            'kind' => RewardPointMovementKind::Credit,
            'delta' => 10,
            'operation_key' => $key,
            'aggregate_operation_key' => $key,
            'linked_movement_id' => null,
            'reservation_key' => null,
            'award_id' => null,
            'actor_type' => 'system',
            'actor_id' => null,
            'reason' => 'test-credit',
            'occurred_at' => now('UTC'),
        ];
    }
}
