<?php

use AIArmada\Events\Enums\RegistrationMode as PackageRegistrationMode;
use AIArmada\Events\Enums\ScheduleKind;
use AIArmada\Events\Models\EventAccessPolicy;
use AIArmada\Events\Models\EventTimeExpression;
use App\Actions\Contributions\ApplyDirectContributionUpdateAction;
use App\Actions\Events\PrepareAdvancedParentProgramSubmissionAction;
use App\Actions\Events\ResolveAdvancedBuilderContextAction;
use App\Actions\Events\ResolveAdvancedBuilderMembershipOptionsAction;
use App\Actions\Events\SyncEventResourceRelationsAction;
use App\Actions\Events\SyncEventScheduleAction;
use App\Enums\TimingMode;
use App\Models\Event;
use App\Models\EventChangeAnnouncement;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use App\Support\Api\Frontend\FrontendFormContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('prepares advanced parent program submissions with utc timestamps and resolved location ownership', function () {
    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified']);
    $locationInstitution = Institution::factory()->create(['status' => 'verified']);

    $user->persons()()->syncWithoutDetaching([$person->id]);
    $user->institutions()->syncWithoutDetaching([$locationInstitution->id]);

    $prepared = app(PrepareAdvancedParentProgramSubmissionAction::class)->handle($user, [
        'timezone' => 'Asia/Kuala_Lumpur',
        'primary_organizer_id' => $person->id,
        'location_institution_id' => $locationInstitution->id,
        'program_starts_at' => '2026-04-10T20:00',
        'program_ends_at' => '2026-04-10T22:00',
    ]);

    expect($prepared)->not->toHaveKey('organizer_type')
        ->and($prepared)->not->toHaveKey('organizer_id')
        ->and($prepared['primary_organizer'])->toBeInstanceOf(Person::class)
        ->and($prepared['primary_organizer']->is($person))->toBeTrue()
        ->and($prepared['location_institution_id'])->toBe($locationInstitution->id)
        ->and($prepared['program_starts_at']->format('Y-m-d H:i:s'))->toBe('2026-04-10 12:00:00')
        ->and($prepared['program_ends_at']->format('Y-m-d H:i:s'))->toBe('2026-04-10 14:00:00');
});

it('resolves advanced builder context with requested institution defaults', function () {
    $user = User::factory()->create();
    $preferredInstitution = Institution::factory()->create(['name' => 'Masjid Pilihan', 'status' => 'verified']);
    $secondaryInstitution = Institution::factory()->create(['name' => 'Masjid Kedua', 'status' => 'verified']);

    $user->institutions()->syncWithoutDetaching([$secondaryInstitution->id, $preferredInstitution->id]);

    $context = app(ResolveAdvancedBuilderContextAction::class)->handle($user, $preferredInstitution->id);

    expect($context['institution_options'])->toHaveKey($preferredInstitution->id, 'Masjid Pilihan')
        ->and($context['default_form'])->not->toHaveKey('organizer_type')
        ->and($context['default_form'])->not->toHaveKey('organizer_id')
        ->and($context['default_form']['primary_organizer_id'])->toBe($preferredInstitution->id)
        ->and($context['default_form']['location_institution_id'])->toBe($preferredInstitution->id)
        ->and($context['default_form']['registration_required'])->toBeFalse();
});

it('publishes the advanced event contract with the primary organizer field and grouped options', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjid Kontrak', 'status' => 'verified']);
    $person = Person::factory()->create(['name' => 'Penceramah Kontrak', 'status' => 'verified']);

    $user->institutions()->syncWithoutDetaching([$institution->id]);
    $user->persons()()->syncWithoutDetaching([$person->id]);

    $contract = app(FrontendFormContractService::class)->advancedEvent($user);

    expect($contract['defaults'])->not->toHaveKey('organizer_type')
        ->and($contract['defaults'])->not->toHaveKey('organizer_id')
        ->and($contract['defaults']['primary_organizer_id'])->toBe($institution->id)
        ->and(collect($contract['fields'])->pluck('name'))->toContain('primary_organizer_id')
        ->and($contract['options']['primary_organizer_options']['institution'])->toHaveKey($institution->id, 'Masjid Kontrak')
        ->and($contract['options']['primary_organizer_options']['speaker'])->toHaveKey($person->id, 'Penceramah Kontrak')
        ->and($contract['options']['location_institution_options'])->toHaveKey($institution->id, 'Masjid Kontrak');
});

it('resolves advanced builder membership options from active member organizers only', function () {
    $user = User::factory()->create();
    $activeInstitution = Institution::factory()->create(['name' => 'Masjid Aktif', 'status' => 'verified']);
    $inactiveInstitution = Institution::factory()->create(['name' => 'Masjid Pasif', 'status' => 'inactive']);
    $activePerson = Person::factory()->create(['name' => 'Person Aktif', 'status' => 'verified']);
    $inactivePerson = Person::factory()->create(['name' => 'Person Pasif', 'status' => 'inactive']);

    $user->institutions()->syncWithoutDetaching([$activeInstitution->id, $inactiveInstitution->id]);
    $user->persons()()->syncWithoutDetaching([$activePerson->id, $inactivePerson->id]);

    $options = app(ResolveAdvancedBuilderMembershipOptionsAction::class)->handle($user);

    expect($options['institution_options'])->toBe([$activeInstitution->id => 'Masjid Aktif'])
        ->and($options['person_options'])->toBe([$activePerson->id => 'Person Aktif']);
});

it('syncs event resource relations and persists the requested registration mode', function () {
    $event = Event::factory()->create();

    $person = Person::factory()->create(['status' => 'verified']);
    $result = app(SyncEventResourceRelationsAction::class)->handle($event, [
        'registration_required' => false,
        'domain_tags' => [],
        'discipline_tags' => [],
        'source_tags' => [],
        'issue_tags' => [],
        'languages' => [],
        'persons' => [$person->id],
        'other_key_people' => [],
    ]);

    $event->refresh();
    $event->load(['accessPolicy', 'persons']);

    expect($result)->toMatchArray([
        'registration_mode' => PackageRegistrationMode::None->value,
        'registration_mode_locked' => false,
    ])
        ->and($event->accessPolicy?->registration_required)->toBeFalse()
        ->and($event->resolvedRegistrationMode())->toBe(PackageRegistrationMode::None)
        ->and($event->speakers->pluck('id')->all())->toBe([$person->id]);
});

it('persists the direction of prayer-relative offsets', function (): void {
    $event = Event::factory()->create();

    app(SyncEventScheduleAction::class)->execute(
        event: $event,
        scheduleKind: ScheduleKind::Single,
        startsAt: Carbon::parse('2026-05-01 18:45:00'),
        endsAt: Carbon::parse('2026-05-01 20:00:00'),
        timezone: 'Asia/Kuala_Lumpur',
        timingMode: TimingMode::PrayerRelative,
        prayerReference: 'maghrib',
        prayerOffset: -15,
    );

    $expression = EventTimeExpression::query()
        ->where('event_id', $event->id)
        ->where('anchor_type', 'prayer')
        ->first();

    expect($expression?->relation)->toBe('before')
        ->and($expression?->offset_minutes)->toBe(15)
        ->and($event->fresh()?->prayer_offset)->toBe('before_15');
});

it('uses a safe database default when creating event settings without an explicit registration flag', function () {
    $event = Event::factory()->create();
    $event->accessPolicy()->delete();

    $settings = EventAccessPolicy::query()->create([
        'event_id' => $event->id,
    ]);

    expect($settings->fresh()?->registration_required)->toBeFalse();
});

it('applies direct contribution edits without changing approved event state for sensitive ordinary saves', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);
    $event = Event::factory()->for($institution)->create([
        'status' => 'approved',
        'starts_at' => now()->addDays(4),
        'ends_at' => now()->addDays(4)->addHour(),
    ]);

    app(ApplyDirectContributionUpdateAction::class)->handle($event, [
        'starts_at' => now()->addDays(8)->toDateTimeString(),
    ]);

    expect((string) $event->fresh()->status)->toBe('approved')
        ->and(EventChangeAnnouncement::query()->where('event_id', $event->id)->exists())->toBeFalse();
});
