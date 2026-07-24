<?php

declare(strict_types=1);

use App\Actions\Contributions\ApproveContributionRequestAction;
use App\Actions\Contributions\CancelContributionRequestAction;
use App\Actions\Contributions\RejectContributionRequestAction;
use App\Actions\Reports\SaveReportAction;
use App\Contracts\CaptchaVerifier;
use App\Contracts\GitHubIssueReporterContract;
use App\Contracts\NullGitHubIssueReporter;
use App\Contracts\ShareTrackingContract;
use App\Enums\ContributionRequestStatus;
use App\Enums\ContributionRequestType;
use App\Enums\ContributionSubjectType;
use App\Enums\EventVisibility;
use App\Models\ContributionRequest;
use App\Models\Event;
use App\Models\Inspiration;
use App\Models\Person;
use App\Models\User;
use App\Models\Venue;
use App\Services\ShareTrackingService;
use App\States\EventStatus\Approved;
use App\States\EventStatus\Cancelled;
use App\States\EventStatus\Pending;
use App\States\EventStatus\Transitions\CancelEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('sets cancelled_at when cancelling an event', function () {
    $moderator = User::factory()->create();
    $event = Event::factory()->create([
        'status' => Pending::class,
        'visibility' => EventVisibility::Public,
        'published_at' => now(),
    ]);

    $cancelled = new CancelEvent($event, $moderator, 'Test cancel')->handle();

    expect($cancelled->status)->toBeInstanceOf(Cancelled::class)
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and($cancelled->last_state_change_at)->not->toBeNull();
});

it('records distinct contribution request timestamps', function () {
    $proposer = User::factory()->create();
    $reviewer = User::factory()->create();

    $person = Person::factory()->create(['status' => 'verified', 'name' => 'Original']);

    $approveRequest = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Person,
        'entity_type' => $person->getMorphClass(),
        'entity_id' => $person->getKey(),
        'proposer_id' => $proposer->id,
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => ['name' => 'Updated'],
        'original_data' => ['name' => 'Original'],
    ]);

    $approved = app(ApproveContributionRequestAction::class)->handle($approveRequest, $reviewer);

    expect($approved->status)->toBe(ContributionRequestStatus::Approved)
        ->and($approved->approved_at)->not->toBeNull()
        ->and($approved->rejected_at)->toBeNull()
        ->and($approved->last_state_change_at)->not->toBeNull();

    $rejectRequest = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Person,
        'entity_type' => $person->getMorphClass(),
        'entity_id' => $person->getKey(),
        'proposer_id' => $proposer->id,
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => ['name' => 'Nope'],
        'original_data' => ['name' => 'Original'],
    ]);

    $rejected = app(RejectContributionRequestAction::class)->handle(
        $rejectRequest,
        $reviewer,
        'incomplete_info',
        'Missing details',
    );

    expect($rejected->status)->toBe(ContributionRequestStatus::Rejected)
        ->and($rejected->rejected_at)->not->toBeNull()
        ->and($rejected->approved_at)->toBeNull()
        ->and($rejected->last_state_change_at)->not->toBeNull();

    $cancelRequest = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'subject_type' => ContributionSubjectType::Person,
        'proposer_id' => $proposer->id,
        'status' => ContributionRequestStatus::Pending,
    ]);

    $cancelled = app(CancelContributionRequestAction::class)->handle($cancelRequest, $proposer);

    expect($cancelled->status)->toBe(ContributionRequestStatus::Cancelled)
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and($cancelled->last_state_change_at)->not->toBeNull();
});

it('uses status for speaker listing instead of is_active column', function () {
    expect(Schema::hasColumn('speakers', 'is_active'))->toBeFalse()
        ->and(Schema::hasColumn('speakers', 'verified_at'))->toBeTrue()
        ->and(Schema::hasColumn('speakers', 'inactive_at'))->toBeTrue();

    $verified = Person::factory()->create(['status' => 'verified']);
    $inactive = Person::factory()->create(['status' => 'inactive']);

    $activeIds = Person::query()->active()->pluck('id')->all();

    expect($activeIds)->toContain($verified->id)
        ->and($activeIds)->not->toContain($inactive->id);
});

it('uses status for inspirations and venues without is_active', function () {
    expect(Schema::hasColumn('inspirations', 'is_active'))->toBeFalse()
        ->and(Schema::hasColumn('venues', 'is_active'))->toBeFalse();

    $active = Inspiration::factory()->create(['status' => 'active']);
    $inactive = Inspiration::factory()->create(['status' => 'inactive']);

    $ids = Inspiration::query()->active()->pluck('id')->all();

    expect($ids)->toContain($active->id)
        ->and($ids)->not->toContain($inactive->id);

    $venue = Venue::factory()->create(['status' => 'verified']);

    expect(Venue::query()->active()->whereKey($venue->id)->exists())->toBeTrue();
});

it('does not expose is_active on event searchable payload', function () {
    $event = Event::factory()->create([
        'status' => Approved::class,
        'visibility' => EventVisibility::Public,
        'published_at' => now(),
    ]);

    $payload = $event->toSearchableArray();

    expect($payload)->not->toHaveKey('is_active')
        ->and($payload)->toHaveKey('status');
});

it('centralises report status timestamps on transition', function () {
    $person = Person::factory()->create(['status' => 'verified']);

    $report = app(SaveReportAction::class)->handle([
        'entity_type' => 'speaker',
        'entity_id' => (string) $person->getKey(),
        'category' => 'wrong_info',
        'description' => 'Test report',
        'status' => 'open',
    ]);

    expect($report->status)->toBe('open')
        ->and($report->reported_at)->not->toBeNull()
        ->and($report->resolved_at)->toBeNull();

    $resolved = app(SaveReportAction::class)->handle([
        'entity_type' => 'speaker',
        'entity_id' => (string) $person->getKey(),
        'category' => 'wrong_info',
        'description' => 'Test report',
        'status' => 'resolved',
    ], $report);

    expect($resolved->status)->toBe('resolved')
        ->and($resolved->resolved_at)->not->toBeNull();

    $dismissed = app(SaveReportAction::class)->handle([
        'entity_type' => 'speaker',
        'entity_id' => (string) $person->getKey(),
        'category' => 'wrong_info',
        'description' => 'Test report',
        'status' => 'dismissed',
    ], $resolved->fresh() ?? $resolved);

    expect($dismissed->status)->toBe('dismissed')
        ->and($dismissed->rejected_at)->not->toBeNull();
});

it('binds optional integration contracts without conditional call-site checks', function () {
    $captcha = app(CaptchaVerifier::class);
    $github = app(GitHubIssueReporterContract::class);
    $shareTracking = app(ShareTrackingContract::class);

    expect($captcha)->toBeInstanceOf(CaptchaVerifier::class)
        ->and($github)->toBeInstanceOf(GitHubIssueReporterContract::class)
        ->and($shareTracking)->toBeInstanceOf(ShareTrackingService::class)
        ->and($github->isConfigured())->toBeFalse()
        ->and($github)->toBeInstanceOf(NullGitHubIssueReporter::class)
        ->and($captcha->verify('token'))->toBeBool();
});
