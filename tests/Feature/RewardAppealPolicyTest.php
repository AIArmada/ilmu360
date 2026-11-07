<?php

declare(strict_types=1);

use AIArmada\Rewards\Models\RewardAppeal;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('denies creating an appeal without an award context', function (): void {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('create', RewardAppeal::class))->toBeFalse();
});
