<?php

use App\Features\DonationChannels\Models\DonationChannel;
use App\Features\Institutions\Models\Institution;
use App\Features\References\EntryPoints\Filament\Resources\References\ReferenceResource;
use App\Features\References\Models\Reference;
use App\Features\Reports\Actions\TriageReportAction;
use App\Features\Reports\Api\ReportCommands;
use App\Features\Reports\EntryPoints\Filament\Resources\Reports\Pages\EditReport;
use App\Features\Reports\EntryPoints\Filament\Resources\Reports\Pages\ListReports;
use App\Features\Reports\EntryPoints\Filament\Resources\Reports\ReportResource;
use App\Features\Reports\Models\Report;
use App\Features\Reports\Notifications\ReportResolvedNotification;
use App\Models\User;
use App\Shared\Auth\AccountDirectory;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery\MockInterface;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);
});

it('shows the reported subject title and admin link on the reports index', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $reference = Reference::factory()->create([
        'title' => 'Rujukan Untuk Disemak',
        'status' => 'verified',
    ]);

    $reference->reports()->create([
        'category' => 'fake_reference',
    ]);

    $this->actingAs($administrator)
        ->get(ReportResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee('Rujukan Untuk Disemak')
        ->assertSee(ReferenceResource::getUrl('edit', ['record' => $reference], panel: 'admin'), false);
});

it('shows a donation channel account name when the label is missing', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $donationChannel = DonationChannel::factory()->create([
        'label' => null,
        'recipient' => 'Tabung Amal Utama',
    ]);

    $donationChannel->reports()->create([
        'category' => 'donation_scam',
    ]);

    $this->actingAs($administrator)
        ->get(ReportResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee('Tabung Amal Utama');
});

it('sorts and filters reports through the report type column behind the category alias', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $wrongInfo = Report::factory()->create([
        'status' => Report::STATUS_OPEN,
        'report_type' => 'wrong_info',
        'entity_type' => 'institution',
        'entity_id' => Institution::factory(),
    ]);
    $fakeReference = Report::factory()->create([
        'status' => Report::STATUS_OPEN,
        'report_type' => 'fake_reference',
        'entity_type' => 'institution',
        'entity_id' => Institution::factory(),
    ]);

    Livewire::actingAs($moderator)
        ->test(ListReports::class)
        ->assertCanSeeTableRecords([$wrongInfo, $fakeReference])
        ->sortTable('category', 'asc')
        ->assertCanSeeTableRecords([$fakeReference, $wrongInfo], true)
        ->filterTable('category', 'fake_reference')
        ->assertCanSeeTableRecords([$fakeReference])
        ->assertCanNotSeeTableRecords([$wrongInfo]);
});

it('defaults the reports list to the open tab and switches status tabs', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $open = Report::factory()->create([
        'status' => Report::STATUS_OPEN,
        'entity_type' => 'institution',
        'entity_id' => Institution::factory(),
    ]);
    $triaged = Report::factory()->create([
        'status' => Report::STATUS_TRIAGED,
        'entity_type' => 'institution',
        'entity_id' => Institution::factory(),
    ]);
    $resolved = Report::factory()->create([
        'status' => Report::STATUS_RESOLVED,
        'entity_type' => 'institution',
        'entity_id' => Institution::factory(),
    ]);
    $dismissed = Report::factory()->create([
        'status' => Report::STATUS_DISMISSED,
        'entity_type' => 'institution',
        'entity_id' => Institution::factory(),
    ]);

    $component = Livewire::actingAs($moderator)->test(ListReports::class);

    expect(array_keys($component->instance()->getTabs()))
        ->toBe(['open', 'triaged', 'resolved', 'dismissed', 'all'])
        ->and($component->instance()->getDefaultActiveTab())->toBe(Report::STATUS_OPEN);

    $component
        ->assertSet('activeTab', Report::STATUS_OPEN)
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$triaged, $resolved, $dismissed])
        ->set('activeTab', 'triaged')
        ->assertCanSeeTableRecords([$triaged])
        ->assertCanNotSeeTableRecords([$open, $resolved, $dismissed])
        ->set('activeTab', 'resolved')
        ->assertCanSeeTableRecords([$resolved])
        ->assertCanNotSeeTableRecords([$open, $triaged, $dismissed])
        ->set('activeTab', 'dismissed')
        ->assertCanSeeTableRecords([$dismissed])
        ->assertCanNotSeeTableRecords([$open, $triaged, $resolved])
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$open, $triaged, $resolved, $dismissed]);
});

it('moderates reports from the admin list through the shared actions', function () {
    Notification::fake();

    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');
    $reporter = User::factory()->create();

    $attributes = [
        'reporter_id' => $reporter->getKey(),
        'reporter_type' => (new User)->getMorphClass(),
        'entity_type' => 'institution',
        'entity_id' => Institution::factory(),
    ];

    $open = Report::factory()->create($attributes + ['status' => Report::STATUS_OPEN]);
    $toResolve = Report::factory()->create($attributes + ['status' => Report::STATUS_TRIAGED]);
    $toDismiss = Report::factory()->create($attributes + ['status' => Report::STATUS_TRIAGED]);
    $toReopen = Report::factory()->create($attributes + ['status' => Report::STATUS_DISMISSED]);

    $component = Livewire::actingAs($moderator)->test(ListReports::class);

    $component
        ->callTableAction('triage', $open->getKey(), data: ['resolution_note' => 'Assigning to a moderator.'])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Report marked as triaged');

    expect($open->fresh()->status)->toBe(Report::STATUS_TRIAGED)
        ->and($open->fresh()->handled_by)->toBe($moderator->getKey());

    $component->set('activeTab', 'triaged')
        ->callTableAction('resolve', $toResolve->getKey(), data: ['resolution_note' => 'Fixed upstream.'])
        ->assertNotified('Report resolved');

    expect($toResolve->fresh()->status)->toBe(Report::STATUS_RESOLVED)
        ->and($toResolve->fresh()->resolution_note)->toBe('Fixed upstream.');

    Notification::assertSentTo($reporter, ReportResolvedNotification::class);

    $component
        ->callTableAction('dismiss', $toDismiss->getKey(), data: ['resolution_note' => 'Not actionable.'])
        ->assertNotified('Report dismissed');

    expect($toDismiss->fresh()->status)->toBe(Report::STATUS_DISMISSED);

    Notification::assertSentToTimes($reporter, ReportResolvedNotification::class, 2);

    $component->set('activeTab', 'dismissed')
        ->callTableAction('reopen', $toReopen->getKey())
        ->assertNotified('Report reopened');

    expect($toReopen->fresh()->status)->toBe(Report::STATUS_OPEN)
        ->and($toReopen->fresh()->handled_by)->toBeNull()
        ->and($toReopen->fresh()->resolution_note)->toBeNull();
});

it('moderates a report from the edit page and returns to the edit page', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $report = Report::factory()->create([
        'status' => Report::STATUS_OPEN,
        'entity_type' => 'institution',
        'entity_id' => Institution::factory(),
    ]);

    Livewire::actingAs($moderator)
        ->test(EditReport::class, ['record' => $report->getKey()])
        ->assertActionVisible('resolve')
        ->callAction('resolve', ['resolution_note' => 'Handled from the edit page.'])
        ->assertNotified('Report resolved')
        ->assertRedirect(ReportResource::getUrl('edit', ['record' => $report]));

    expect($report->fresh()->status)->toBe(Report::STATUS_RESOLVED)
        ->and($report->fresh()->handled_by)->toBe($moderator->getKey())
        ->and($report->fresh()->resolution_note)->toBe('Handled from the edit page.');
});

it('rejects stale report triage transitions with a validation error', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $report = Report::factory()->create([
        'status' => Report::STATUS_RESOLVED,
        'entity_type' => 'institution',
        'entity_id' => Institution::factory(),
    ]);

    expect(fn () => app(TriageReportAction::class)->handle(
        (string) $report->getKey(),
        'triage',
        null,
        $administrator,
    ))->toThrow(ValidationException::class);

    expect($report->fresh()->status)->toBe(Report::STATUS_RESOLVED);
});

it('shows a danger notification when a report transition is stale on the edit page', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $report = Report::factory()->create([
        'status' => Report::STATUS_OPEN,
        'handled_by' => null,
        'entity_type' => 'institution',
        'entity_id' => Institution::factory(),
    ]);

    $this->mock(ReportCommands::class, function (MockInterface $mock): void {
        $mock->shouldReceive('triage')
            ->once()
            ->andThrow(ValidationException::withMessages([
                'status' => __('This report is no longer in a status that allows this action. Please refresh.'),
            ]));
    });

    Livewire::actingAs($moderator)
        ->test(EditReport::class, ['record' => $report->getKey()])
        ->callAction('triage', ['resolution_note' => 'Stale moderation attempt.'])
        ->assertNotified(__('This report is no longer in a status that allows this action. Please refresh.'))
        ->assertRedirect(ReportResource::getUrl('edit', ['record' => $report]));

    expect($report->fresh()->status)->toBe(Report::STATUS_OPEN)
        ->and($report->fresh()->handled_by)->toBeNull();
});

it('keeps the report transition when the reporter notification fails', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $reporter = User::factory()->create();
    $report = Report::factory()->create([
        'reporter_id' => $reporter->getKey(),
        'reporter_type' => (new User)->getMorphClass(),
        'status' => Report::STATUS_OPEN,
        'entity_type' => 'institution',
        'entity_id' => Institution::factory(),
    ]);

    $notifiable = Mockery::mock(User::class);
    $notifiable->shouldReceive('notify')->andThrow(new RuntimeException('Mail transport down.'));

    $this->mock(AccountDirectory::class, function (MockInterface $mock) use ($notifiable): void {
        $mock->shouldReceive('resolveNotifiable')->andReturn($notifiable);
    });

    $updated = app(TriageReportAction::class)->handle(
        (string) $report->getKey(),
        'resolve',
        'Resolved despite the mail failure.',
        $administrator,
    );

    expect($updated->status)->toBe(Report::STATUS_RESOLVED)
        ->and($report->fresh()->status)->toBe(Report::STATUS_RESOLVED);
});
