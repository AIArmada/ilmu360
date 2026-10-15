<?php

declare(strict_types=1);

use AIArmada\Rewards\Actions\CreateRewardProgramAction;
use AIArmada\Rewards\Actions\EnrollRewardMemberAction;
use AIArmada\Rewards\Actions\EvaluateRewardEntitlementAction;
use AIArmada\Rewards\Actions\PublishRewardProgramVersionAction;
use AIArmada\Rewards\Actions\RecordRewardFundingAction;
use AIArmada\Rewards\Actions\RecordRewardFundingSourceAction;
use AIArmada\Rewards\Actions\TransitionRewardProgramStatusAction;
use AIArmada\Rewards\Contracts\RewardSourceFactReader;
use AIArmada\Rewards\Enums\RewardBeneficiaryRole;
use AIArmada\Rewards\Enums\RewardComponent;
use AIArmada\Rewards\Enums\RewardProgramMode;
use AIArmada\Rewards\Enums\RewardProgramStatus;
use AIArmada\Rewards\Jobs\EvaluateRewardEntitlementJob;
use AIArmada\Rewards\Models\RewardAward;
use AIArmada\Rewards\Models\RewardFundingEntry;
use AIArmada\Rewards\Models\RewardFundingSource;
use App\Enums\DawahShareOutcomeType;
use App\Models\EventCheckin;
use App\Models\ShareSourceAttributionReceipt;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\ShareTracking\ActivityRewardBusinessKey;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Host\Support\RewardLedgerFixtures;

uses(RefreshDatabase::class);

it('runs a staff-managed funded programme through explicit enrolment and a direct check-in award', function (): void {
    Queue::fake([EvaluateRewardEntitlementJob::class]);

    $staff = User::factory()->create();
    $member = User::factory()->create();
    $family = 'slice3-lifecycle-'.Str::lower(Str::random(8));
    $program = CreateRewardProgramAction::run(
        $family,
        'Test funded points programme',
        RewardProgramMode::Combined,
        null,
        null,
        'slice3-lifecycle-test',
    );

    $rules = RewardLedgerFixtures::rules(RewardProgramMode::Combined);
    $rules['components'] = [
        ['type' => 'points', 'points' => 17, 'unit' => 'pts'],
        ['type' => 'cash', 'minor_units' => 100, 'currency' => 'MYR'],
    ];
    $rules['eligibility']['outcomes'] = ['event_checkin'];
    $rules['eligibility']['min_verification'] = 'staff_verified';
    $rules['windows']['preclaim_lookback_days'] = 0;

    $publishedAt = CarbonImmutable::now('UTC')->subSecond();
    $version = app(PublishRewardProgramVersionAction::class)->handle(
        $program,
        $publishedAt,
        $rules,
        RewardLedgerFixtures::terms(RewardProgramMode::Combined),
        'Staff-created test economics',
        'user',
        (string) $staff->getKey(),
        $publishedAt,
    );

    $ready = TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    expect($ready->status)->toBe(RewardProgramStatus::Ready);

    expect(fn () => TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active))
        ->toThrow(InvalidArgumentException::class, 'no-confirmed-funding:MYR');
    expect($program->refresh()->status)->toBe(RewardProgramStatus::Ready);

    $sourceRef = 'SLICE3-'.$family;
    RecordRewardFundingSourceAction::run(
        $sourceRef,
        'MYR',
        10000,
        'user',
        (string) $staff->getKey(),
        'staff',
        ['test_fixture' => true],
        $publishedAt,
    );
    RecordRewardFundingAction::run(
        $program,
        'MYR',
        10000,
        $sourceRef,
        'user',
        (string) $staff->getKey(),
        'staff',
        ['test_fixture' => true],
        $publishedAt,
    );

    $active = TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);
    EnrollRewardMemberAction::run(
        $active,
        'user',
        (string) $member->getKey(),
        $version,
        'member-portal',
        CarbonImmutable::now('UTC'),
    );

    $checkin = EventCheckin::factory()->create([
        'attendee_type' => $member->getMorphClass(),
        'attendee_id' => $member->getKey(),
        'verified_by_user_id' => $staff->getKey(),
        'checked_in_at' => CarbonImmutable::now('UTC'),
        'attendance_type' => 'check_in',
    ]);
    $shareOutcomeKey = ShareOutcomeBusinessKey::eventCheckin((string) $checkin->getKey());
    $activityKey = ActivityRewardBusinessKey::for(
        DawahShareOutcomeType::EventCheckin,
        'checkin:'.(string) $checkin->getKey(),
    );

    app(ShareTrackingService::class)->recordOutcome(
        DawahShareOutcomeType::EventCheckin,
        $shareOutcomeKey,
        actor: $member,
        occurredAt: $checkin->checked_in_at,
    );

    $receipt = ShareSourceAttributionReceipt::query()->where('business_key', $shareOutcomeKey)->firstOrFail();
    expect($receipt->isAttributed())->toBeFalse();
    Queue::assertPushed(EvaluateRewardEntitlementJob::class, fn (EvaluateRewardEntitlementJob $job): bool => $job->businessKey === $activityKey);

    (new EvaluateRewardEntitlementAction(app(RewardSourceFactReader::class)))->handle(
        $active,
        RewardComponent::Points,
        'user',
        (string) $member->getKey(),
        RewardBeneficiaryRole::Participant,
        $activityKey,
        CarbonImmutable::now('UTC'),
    );

    $award = RewardAward::query()
        ->where('program_id', $active->getKey())
        ->where('source_business_key', $activityKey)
        ->where('recipient_id', (string) $member->getKey())
        ->where('component', RewardComponent::Points->value)
        ->firstOrFail();

    expect($active->status)->toBe(RewardProgramStatus::Active)
        ->and($version->rules['components'][0]['points'])->toBe(17)
        ->and(RewardFundingSource::query()->where('source_ref', $sourceRef)->count())->toBe(1)
        ->and(RewardFundingEntry::query()->where('program_id', $active->getKey())->count())->toBe(1)
        ->and(data_get($award->amount_snapshot, 'points'))->toBe(17);
});
