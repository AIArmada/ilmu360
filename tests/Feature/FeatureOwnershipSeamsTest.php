<?php

use AIArmada\Membership\Enums\MemberRole;
use App\Features\DonationChannels\Api\DonationChannelLookup;
use App\Features\DonationChannels\Queries\EloquentDonationChannelLookup;
use App\Features\Identity\Api\AccountSubmissionEligibility;
use App\Features\Identity\Services\AccountSubmissionEligibilityService;
use App\Features\Institutions\Api\InstitutionLookup;
use App\Features\Institutions\Api\InstitutionSubmissionAccess;
use App\Features\Institutions\Api\InstitutionVenueLinks;
use App\Features\Institutions\Api\InstitutionWorkflow;
use App\Features\Institutions\Queries\EloquentInstitutionLookup;
use App\Features\Institutions\Services\InstitutionVenueLinkService;
use App\Features\Persons\Api\PersonLookup;
use App\Features\Persons\Api\PersonSubmissionAccess;
use App\Features\Persons\Api\PersonWorkflow;
use App\Features\Persons\Queries\EloquentPersonLookup;
use App\Features\Places\Api\InstitutionSpaceLinks;
use App\Features\Places\Api\PlaceLookup;
use App\Features\Places\Api\SpaceWorkflow;
use App\Features\Places\Api\VenueWorkflow;
use App\Features\Places\Queries\EloquentPlaceLookup;
use App\Features\Places\Services\InstitutionSpaceLinkService;
use App\Features\Places\Services\SpaceWorkflowService;
use App\Features\Places\Services\VenueWorkflowService;
use App\Features\References\Api\ReferenceLookup;
use App\Features\References\Queries\EloquentReferenceLookup;
use App\Features\Reports\Services\CompositeReportSubjectResolver;
use App\Models\DonationChannel;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Space;
use App\Models\User;
use App\Models\Venue;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Directory\Enums\InstitutionStatus;
use App\Shared\Reports\ReportSubjectResolver;
use App\Support\Api\Frontend\FrontendCatalogService;

it('binds the directory seams to their owning implementations', function (): void {
    expect(app(InstitutionLookup::class))->toBeInstanceOf(EloquentInstitutionLookup::class)
        ->and(app(PersonLookup::class))->toBeInstanceOf(EloquentPersonLookup::class)
        ->and(app(ReferenceLookup::class))->toBeInstanceOf(EloquentReferenceLookup::class)
        ->and(app(PlaceLookup::class))->toBeInstanceOf(EloquentPlaceLookup::class)
        ->and(app(DonationChannelLookup::class))->toBeInstanceOf(EloquentDonationChannelLookup::class)
        ->and(app(VenueWorkflow::class))->toBeInstanceOf(VenueWorkflowService::class)
        ->and(app(SpaceWorkflow::class))->toBeInstanceOf(SpaceWorkflowService::class)
        ->and(app(InstitutionVenueLinks::class))->toBeInstanceOf(InstitutionVenueLinkService::class)
        ->and(app(InstitutionSpaceLinks::class))->toBeInstanceOf(InstitutionSpaceLinkService::class)
        ->and(app(ReportSubjectResolver::class))->toBeInstanceOf(CompositeReportSubjectResolver::class)
        ->and(app(AccountSubmissionEligibility::class))->toBeInstanceOf(AccountSubmissionEligibilityService::class);
});

it('resolves registered morph aliases and rejects unknown ones', function (): void {
    expect(MorphModelResolver::resolve('institution'))->toBe(Institution::class)
        ->and(MorphModelResolver::resolve('person'))->toBe(Person::class)
        ->and(MorphModelResolver::resolve('reference'))->toBe(Reference::class)
        ->and(MorphModelResolver::resolve('venue'))->toBe(Venue::class)
        ->and(MorphModelResolver::resolve('space'))->toBe(Space::class)
        ->and(MorphModelResolver::resolve('donation_channel'))->toBe(DonationChannel::class)
        ->and(fn () => MorphModelResolver::resolve('not_a_registered_alias'))->toThrow(RuntimeException::class);
});

it('reads owner summaries by id or slug and serves form options', function (): void {
    $institution = Institution::factory()->create(['name' => 'Masjid Ilmu']);
    $person = Person::factory()->create(['name' => 'Ahmad Ilmu']);

    $institutions = app(InstitutionLookup::class);
    $persons = app(PersonLookup::class);
    $institutionId = (string) $institution->getKey();

    $byId = $institutions->find($institutionId);
    $bySlug = $institutions->find((string) $institution->slug);
    $personDetail = $persons->find((string) $person->getKey());

    expect($byId?->title)->toBe('Masjid Ilmu')
        ->and($byId?->publicUrl)->toBe(route('institutions.show', $institution))
        ->and($bySlug?->id)->toBe($institutionId)
        ->and($institutions->resolveIdentifier($institutionId))->toBe($institutionId)
        ->and($institutions->resolveIdentifier((string) $institution->slug))->toBe($institutionId)
        ->and($institutions->resolveIdentifier('missing-record'))->toBeNull()
        ->and($personDetail?->title)->toBe($person->formatted_name)
        ->and($institutions->find($institutionId) !== null)->toBeTrue()
        ->and($institutions->find('missing-record'))->toBeNull();

    $options = collect(app(FrontendCatalogService::class)->submitInstitutions(null, 'Masjid Ilmu'))->keyBy('id');

    expect($options->has($institutionId))->toBeTrue()
        ->and($options->get($institutionId)['label'] ?? null)->toBe($institution->display_name);
});

it('reads reference details by slug and finds the reference in the form catalog', function (): void {
    $reference = Reference::factory()->create([
        'title' => 'Kitab Seam',
        'slug' => 'kitab-seam',
        'status' => 'verified',
    ]);

    $detail = app(ReferenceLookup::class)->find((string) $reference->slug);
    $options = collect(app(FrontendCatalogService::class)->references('Kitab Seam'))->keyBy('id');

    expect($detail?->id)->toBe((string) $reference->getKey())
        ->and($detail?->title)->toBe($reference->displayTitle())
        ->and($detail?->publicUrl)->toBe(route('references.show', $reference))
        ->and($options->has((string) $reference->getKey()))->toBeTrue();
});

it('composes directory and event report subjects without importing models', function (): void {
    $institution = Institution::factory()->create(['name' => 'Masjid Laporan']);
    $reference = Reference::factory()->create(['title' => 'Kitab Laporan', 'status' => 'verified']);
    $event = Event::factory()->create(['title' => 'Majlis Laporan']);

    $resolver = app(ReportSubjectResolver::class);
    $institutionSubject = $resolver->find('institution', (string) $institution->getKey());
    $referenceSubject = $resolver->find('reference', (string) $reference->getKey());
    $eventSubject = $resolver->find('event', (string) $event->getKey());

    expect($resolver->validKeys())->toBe(['event', 'institution', 'person', 'reference', 'donation_channel'])
        ->and($institutionSubject?->title)->toBe('Masjid Laporan')
        ->and($institutionSubject?->publicUrl)->toBe(route('institutions.show', $institution))
        ->and($referenceSubject?->title)->toBe('Kitab Laporan')
        ->and($referenceSubject?->publicUrl)->toBe(route('references.show', $reference))
        ->and($eventSubject?->title)->toBe('Majlis Laporan')
        ->and($eventSubject?->publicUrl)->toBe(route('events.show', $event))
        ->and($resolver->exists('institution', (string) $institution->getKey()))->toBeTrue()
        ->and($resolver->exists('reference', (string) $reference->getKey()))->toBeTrue()
        ->and($resolver->find('unknown_subject', 'id'))->toBeNull();
});

it('creates, updates, verifies and deletes institutions through their owner workflow', function (): void {
    $actor = User::factory()->create();
    $workflow = app(InstitutionWorkflow::class);

    $id = $workflow->saveFromAdmin([
        'name' => 'Masjid Perintah',
        'type' => 'masjid',
    ], (string) $actor->getKey());
    expect(Institution::query()->findOrFail($id)->name)->toBe('Masjid Perintah');

    $workflow->saveFromAdmin(['name' => 'Masjid Diperbaharui'], (string) $actor->getKey(), $id);
    expect(Institution::query()->findOrFail($id)->name)->toBe('Masjid Diperbaharui');

    $workflow->changeStatus($id, InstitutionStatus::Verified->value);
    expect(Institution::query()->findOrFail($id)->status)->toBe(InstitutionStatus::Verified);

    $workflow->delete([$id]);

    expect(Institution::query()->find($id))->toBeNull();
});

it('applies a contribution payload without changing the record lifecycle', function (): void {
    $person = Person::factory()->create(['name' => 'Nama Asal']);

    $status = $person->status;
    $changes = app(PersonWorkflow::class)->applyContribution((string) $person->getKey(), ['name' => 'Nama Baharu']);

    expect($changes)->toHaveKey('name')
        ->and($person->refresh()->name)->toBe('Nama Baharu')
        ->and($person->status)->toBe($status);
});

it('lists current directory records as scalar options', function (): void {
    $institution = Institution::factory()->create(['name' => 'Masjid Ujian']);
    $person = Person::factory()->create(['name' => 'Ahmad Ujian']);

    $catalogs = app(FrontendCatalogService::class);
    $institutionId = (string) $institution->getKey();
    $personId = (string) $person->getKey();

    $institutions = collect($catalogs->submitInstitutions(null))->keyBy('id');
    $persons = collect($catalogs->submitPersons(null))->keyBy('id');

    expect($institutions->has($institutionId))->toBeTrue()
        ->and($institutions->get($institutionId)['label'] ?? null)->toBe('Masjid Ujian')
        ->and($persons->has($personId))->toBeTrue()
        ->and($persons->get($personId)['label'] ?? null)->toBe('Ahmad Ujian');
});

it('syncs locked directory entities for the account when eligibility fails', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create();
    $person = Person::factory()->create();

    addTestMember($institution, $user, MemberRole::Viewer);
    addTestMember($person, $user, MemberRole::Viewer);

    $institution->forceFill(['allow_public_event_submission' => false])->save();
    $person->forceFill(['allow_public_event_submission' => false])->save();

    app(AccountSubmissionEligibility::class)->syncForAccount((string) $user->getKey());

    expect($institution->fresh()?->allow_public_event_submission)->toBeTrue()
        ->and($person->fresh()?->allow_public_event_submission)->toBeTrue();
});

it('keeps a deliberately locked institution locked while eligibility holds', function (): void {
    $owner = User::factory()->create([
        'phone' => '+60123456789',
        'phone_verified_at' => now(),
    ]);
    $institution = Institution::factory()->create();

    addTestMember($institution, $owner, MemberRole::Owner);

    $institution->forceFill(['allow_public_event_submission' => false])->save();

    app(InstitutionSubmissionAccess::class)->reopenIfIneligible((string) $institution->getKey());

    expect($institution->fresh()?->allow_public_event_submission)->toBeFalse();
});

it('rejects missing owner entities and safely syncs eligibility for an account with no matching subjects', function (): void {
    $missingId = '00000000-0000-0000-0000-000000000999';
    $sync = app(AccountSubmissionEligibility::class);
    $user = User::factory()->create();

    expect(fn () => app(InstitutionSubmissionAccess::class)->reopenIfIneligible($missingId))
        ->toThrow(RuntimeException::class, "Institution [{$missingId}] was not found.")
        ->and(fn () => app(PersonSubmissionAccess::class)->reopenIfIneligible($missingId))
        ->toThrow(RuntimeException::class, "Person [{$missingId}] was not found.")
        ->and($sync)->toBeInstanceOf(AccountSubmissionEligibility::class);

    $sync->syncForAccount((string) $user->getKey());
});
