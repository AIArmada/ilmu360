<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

it('keeps the membership teams relation on the user model', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $user->teams()->attach($team, ['role' => TeamRole::Member->value]);

    $attachedTeam = $user->teams()->whereKey($team->getKey())->first();

    expect($attachedTeam)->toBeInstanceOf(Team::class)
        ->and($attachedTeam->is($team))->toBeTrue()
        ->and(data_get($attachedTeam, 'pivot.role'))->toBe(TeamRole::Member->value)
        ->and($user->teams()->toBase()->toSql())->toContain('team_members');
});