<?php

declare(strict_types=1);

use App\Features\Events\Api\AdvancedBuilderMembershipOptions;
use App\Models\User;

it('does not offer unrelated organizers to an account without memberships', function (): void {
    $user = User::factory()->create();

    expect(app(AdvancedBuilderMembershipOptions::class)->forAccount((string) $user->getKey()))
        ->toBe(['institution_options' => [], 'person_options' => []]);
});
