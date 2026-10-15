<?php

declare(strict_types=1);

use AIArmada\Rewards\Actions\EnrollRewardMemberAction;
use AIArmada\Rewards\Actions\TransitionRewardProgramStatusAction;
use AIArmada\Rewards\Enums\RewardProgramMode;
use AIArmada\Rewards\Enums\RewardProgramStatus;
use AIArmada\Rewards\Models\RewardEnrollment;
use AIArmada\Rewards\Models\RewardProgram;
use AIArmada\Rewards\Models\RewardProgramVersion;
use AIArmada\Signals\Models\SignalEvent;
use App\Enums\DawahShareOutcomeType;
use App\Livewire\Pages\Dashboard\UserDashboard;
use App\Models\EventCheckin;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rewards\Host\Support\RewardLedgerFixtures;

uses(RefreshDatabase::class);

/** @return array{0: RewardProgram, 1: RewardProgramVersion} */
function activePromptRewardProgram(string $prefix): array
{
    [$program, $version] = RewardLedgerFixtures::seedVersionedProgram(
        $prefix,
        RewardProgramMode::Points,
        ['eligibility' => ['outcomes' => ['event_registration', 'event_checkin']]],
    );
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    $program = TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    return [$program->refresh(), $version];
}

function makePromptActivityReceipt(User $user): EventCheckin
{
    $checkin = EventCheckin::factory()->create([
        'attendee_type' => $user->getMorphClass(),
        'attendee_id' => $user->getKey(),
        'verified_by_user_id' => User::factory()->create()->getKey(),
        'checked_in_at' => now(),
        'attendance_type' => 'check_in',
    ]);

    app(ShareTrackingService::class)->recordOutcome(
        DawahShareOutcomeType::EventCheckin,
        ShareOutcomeBusinessKey::eventCheckin((string) $checkin->getKey()),
        actor: $user,
        occurredAt: $checkin->checked_in_at,
    );

    return $checkin;
}

it('shows the prompt for an unenrolled user with an activity outcome and enrolls in one tap', function (): void {
    [$program] = activePromptRewardProgram('enrollment-prompt-show');
    $user = User::factory()->create();
    makePromptActivityReceipt($user);

    Livewire::actingAs($user)
        ->test(UserDashboard::class)
        ->assertOk()
        ->assertSee('Activity is waiting.')
        ->assertSee('Enrol now to start earning on eligible activity.')
        ->assertSee('Enrolling does not award points for past activity.')
        ->assertDontSee('You will earn points for past activity.')
        ->call('enrollRewardProgram')
        ->assertOk()
        ->assertDontSee('Activity is waiting.');

    expect(RewardEnrollment::query()
        ->where('program_id', $program->getKey())
        ->where('member_type', $user->getMorphClass())
        ->where('member_id', $user->getKey())
        ->where('status', 'active')
        ->exists())->toBeTrue();

    $signalEvent = SignalEvent::query()
        ->where('event_name', 'rewards.program_enrolled')
        ->latest('occurred_at')
        ->first();

    expect($signalEvent)->not->toBeNull();
    expect(data_get($signalEvent?->properties, 'surface'))->toBe('dashboard-prompt');
});

it('hides the prompt for an enrolled user even when the user has activity outcomes', function (): void {
    [$program, $version] = activePromptRewardProgram('enrollment-prompt-enrolled');
    $user = User::factory()->create();
    makePromptActivityReceipt($user);
    EnrollRewardMemberAction::run($program, 'user', (string) $user->getKey(), $version, 'member-portal');

    Livewire::actingAs($user)
        ->test(UserDashboard::class)
        ->assertOk()
        ->assertDontSee('Activity is waiting.')
        ->assertDontSee('Enrolling does not award points for past activity.');
});

it('hides the prompt when no active programme with published terms exists', function (): void {
    $user = User::factory()->create();
    makePromptActivityReceipt($user);

    Livewire::actingAs($user)
        ->test(UserDashboard::class)
        ->assertOk()
        ->assertDontSee('Activity is waiting.')
        ->assertDontSee('Enrolling does not award points for past activity.');
});

it('does not tease an activity source until its share outcome receipt is banked', function (): void {
    activePromptRewardProgram('enrollment-prompt-unbanked');
    $user = User::factory()->create();
    EventCheckin::factory()->create([
        'attendee_type' => $user->getMorphClass(),
        'attendee_id' => $user->getKey(),
        'verified_by_user_id' => User::factory()->create()->getKey(),
        'checked_in_at' => now(),
        'attendance_type' => 'check_in',
    ]);

    Livewire::actingAs($user)
        ->test(UserDashboard::class)
        ->assertOk()
        ->assertDontSee('Activity is waiting.');
});
