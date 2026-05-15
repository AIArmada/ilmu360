<?php

use App\Enums\ContactCategory;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\User;
use App\Support\Timezone\UserDateTimeFormatter;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
});

it('does not expose submissions as an editable relation manager on the admin event edit page', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $event = Event::factory()->create();

    $component = Livewire::actingAs($administrator)
        ->test(EditEvent::class, ['record' => $event->id]);

    expect($component->instance()->getRelationManagers())
        ->not->toContain('App\\Filament\\Resources\\Events\\RelationManagers\\EventSubmissionsRelationManager');

    $component->assertFormFieldDoesNotExist('submitter_id');
});

it('shows the latest submission as read-only moderation data', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $event = Event::factory()->create([
        'submitter_id' => null,
    ]);

    $submittedAt = Carbon::create(2026, 3, 6, 10, 30, 0, 'UTC');

    $submission = EventSubmission::factory()->create([
        'event_id' => $event->id,
        'submitted_by' => null,
        'submitter_name' => 'Guest Submitter',
        'notes' => 'Needs wheelchair-friendly access and projector setup.',
        'created_at' => $submittedAt,
        'updated_at' => $submittedAt,
    ]);

    $submission->contacts()->create([
        'category' => ContactCategory::Email->value,
        'value' => 'guest@example.com',
    ]);

    $submission->contacts()->create([
        'category' => ContactCategory::Phone->value,
        'value' => '+60112223344',
    ]);

    Livewire::actingAs($administrator)
        ->test(EditEvent::class, ['record' => $event->id])
        ->assertSchemaComponentExists('submission_source')
        ->assertSchemaComponentExists('submission_recorded_at')
        ->assertSchemaComponentExists('submission_submitter')
        ->assertSchemaComponentExists('submission_notes')
        ->assertSchemaComponentStateSet('submission_source', 'Penghantaran awam')
        ->assertSchemaComponentStateSet(
            'submission_recorded_at',
            UserDateTimeFormatter::translatedFormat($submittedAt, 'd M Y').', '.UserDateTimeFormatter::format($submittedAt, 'h:i A'),
        )
        ->assertSchemaComponentStateSet('submission_notes', 'Needs wheelchair-friendly access and projector setup.')
        ->assertSee('Guest Submitter | guest@example.com | +60112223344');
});

it('hides submission reference components when the event has no real submission record', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $event = Event::factory()->create([
        'submitter_id' => User::factory(),
    ]);

    Livewire::actingAs($administrator)
        ->test(EditEvent::class, ['record' => $event->id])
        ->assertSchemaComponentHidden('submission_source')
        ->assertSchemaComponentHidden('submission_recorded_at')
        ->assertSchemaComponentHidden('submission_submitter')
        ->assertSchemaComponentHidden('submission_notes');
});
