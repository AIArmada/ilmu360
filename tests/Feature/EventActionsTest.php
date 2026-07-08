<?php

use AIArmada\Events\Enums\RegistrationMode as PackageRegistrationMode;
use AIArmada\Events\Models\EventAccessPolicy;
use App\Actions\Contributions\ApplyDirectContributionUpdateAction;
use App\Actions\Events\PrepareAdvancedParentProgramSubmissionAction;
use App\Actions\Events\ResolveAdvancedBuilderContextAction;
use App\Actions\Events\ResolveAdvancedBuilderMembershipOptionsAction;
use App\Actions\Events\SyncEventResourceRelationsAction;
use App\Enums\RegistrationMode;
use App\Enums\TagType;
use App\Models\Event;
use App\Models\EventChangeAnnouncement;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\Tag;
use App\Models\User;
use App\Support\Api\Frontend\FrontendFormContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('prepares advanced parent program submissions with utc timestamps and resolved location ownership', function () {
    $user = User::factory()->create();
    $speaker = Speaker::factory()->create(['status' => 'verified', 'is_active' => true]);
    $locationInstitution = Institution::factory()->create(['status' => 'verified', 'is_active' => true]);

    $user->speakers()->syncWithoutDetaching([$speaker->id]);
    $user->institutions()->syncWithoutDetaching([$locationInstitution->id]);

    $prepared = app(PrepareAdvancedParentProgramSubmissionAction::class)->handle($user, [
        'timezone' => 'Asia/Kuala_Lumpur',
        'primary_organizer_id' => $speaker->id,
        'location_institution_id' => $locationInstitution->id,
        'program_starts_at' => '2026-04-10T20:00',
        'program_ends_at' => '2026-04-10T22:00',
    ]);

    expect($prepared)->not->toHaveKey('organizer_type')
        ->and($prepared)->not->toHaveKey('organizer_id')
        ->and($prepared['primary_organizer'])->toBeInstanceOf(Speaker::class)
        ->and($prepared['primary_organizer']->is($speaker))->toBeTrue()
        ->and($prepared['location_institution_id'])->toBe($locationInstitution->id)
        ->and($prepared['program_starts_at']->format('Y-m-d H:i:s'))->toBe('2026-04-10 12:00:00')
        ->and($prepared['program_ends_at']->format('Y-m-d H:i:s'))->toBe('2026-04-10 14:00:00');
});

it('resolves advanced builder context with requested institution defaults', function () {
    $user = User::factory()->create();
    $preferredInstitution = Institution::factory()->create(['name' => 'Masjid Pilihan', 'status' => 'verified', 'is_active' => true]);
    $secondaryInstitution = Institution::factory()->create(['name' => 'Masjid Kedua', 'status' => 'verified', 'is_active' => true]);

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
    $institution = Institution::factory()->create(['name' => 'Masjid Kontrak', 'status' => 'verified', 'is_active' => true]);
    $speaker = Speaker::factory()->create(['name' => 'Penceramah Kontrak', 'status' => 'verified', 'is_active' => true]);

    $user->institutions()->syncWithoutDetaching([$institution->id]);
    $user->speakers()->syncWithoutDetaching([$speaker->id]);

    $contract = app(FrontendFormContractService::class)->advancedEvent($user);

    expect($contract['defaults'])->not->toHaveKey('organizer_type')
        ->and($contract['defaults'])->not->toHaveKey('organizer_id')
        ->and($contract['defaults']['primary_organizer_id'])->toBe($institution->id)
        ->and(collect($contract['fields'])->pluck('name'))->toContain('primary_organizer_id')
        ->and($contract['options']['primary_organizer_options']['institution'])->toHaveKey($institution->id, 'Masjid Kontrak')
        ->and($contract['options']['primary_organizer_options']['speaker'])->toHaveKey($speaker->id, 'Penceramah Kontrak')
        ->and($contract['options']['location_institution_options'])->toHaveKey($institution->id, 'Masjid Kontrak');
});

it('resolves advanced builder membership options from active member organizers only', function () {
    $user = User::factory()->create();
    $activeInstitution = Institution::factory()->create(['name' => 'Masjid Aktif', 'status' => 'verified', 'is_active' => true]);
    $inactiveInstitution = Institution::factory()->create(['name' => 'Masjid Pasif', 'status' => 'verified', 'is_active' => false]);
    $activeSpeaker = Speaker::factory()->create(['name' => 'Speaker Aktif', 'status' => 'pending', 'is_active' => true]);
    $inactiveSpeaker = Speaker::factory()->create(['name' => 'Speaker Pasif', 'status' => 'verified', 'is_active' => false]);

    $user->institutions()->syncWithoutDetaching([$activeInstitution->id, $inactiveInstitution->id]);
    $user->speakers()->syncWithoutDetaching([$activeSpeaker->id, $inactiveSpeaker->id]);

    $options = app(ResolveAdvancedBuilderMembershipOptionsAction::class)->handle($user);

    expect($options['institution_options'])->toBe([$activeInstitution->id => 'Masjid Aktif'])
        ->and($options['speaker_options'])->toBe([$activeSpeaker->id => 'Speaker Aktif']);
});

it('syncs event resource relations and persists the requested registration mode', function () {
    $event = Event::factory()->create();

    $speaker = Speaker::factory()->create(['status' => 'verified']);
    $domainTag = Tag::factory()->create(['type' => TagType::Domain->value, 'status' => 'verified']);
    $issueTag = Tag::factory()->create(['type' => TagType::Issue->value, 'status' => 'verified']);

    $result = app(SyncEventResourceRelationsAction::class)->handle($event, [
        'registration_mode' => RegistrationMode::Event->value,
        'domain_tags' => [$domainTag->id],
        'discipline_tags' => [],
        'source_tags' => [],
        'issue_tags' => [$issueTag->id],
        'languages' => [],
        'speakers' => [$speaker->id],
        'other_key_people' => [],
    ]);

    $event->refresh();
    $event->load(['settings', 'tags', 'speakers']);

    expect($result)->toMatchArray([
        'registration_mode' => RegistrationMode::Event->value,
        'registration_mode_locked' => false,
    ])
        ->and($event->accessPolicy?->registration_required)->toBeFalse()
        ->and($event->resolvedRegistrationMode())->toBe(PackageRegistrationMode::None)
        ->and($event->tags->pluck('id')->sort()->values()->all())->toBe([$domainTag->id, $issueTag->id])
        ->and($event->speakers->pluck('id')->all())->toBe([$speaker->id]);
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
