<?php

use AIArmada\Membership\Enums\MemberRole;
use App\Features\Directory\Api\DirectoryAdminContract;
use App\Features\Directory\Api\DirectoryCommands;
use App\Features\Directory\Api\DirectoryReader;
use App\Features\Directory\Api\DirectoryRecordType;
use App\Features\Directory\Api\DirectoryReportSubjectProvider;
use App\Features\Directory\Services\DirectoryReportSubjectResolver;
use App\Models\DonationChannel;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Models\Venue;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Directory\DirectoryCatalog;
use App\Shared\Directory\SubmissionEligibilitySync;
use App\Shared\Reports\ReportSubjectResolver;
use App\Support\Directory\EloquentDirectoryAdmin;
use App\Support\Directory\EloquentDirectoryCatalog;
use App\Support\Directory\EloquentDirectoryCommands;
use App\Support\Directory\EloquentDirectoryReader;
use App\Support\Reports\CompositeReportSubjectResolver;
use App\Support\Submission\PublicSubmissionEligibilitySync;

it('binds the directory seams to their interim adapters', function (): void {
    expect(app(DirectoryCatalog::class))->toBeInstanceOf(EloquentDirectoryCatalog::class)
        ->and(app(DirectoryReader::class))->toBeInstanceOf(EloquentDirectoryReader::class)
        ->and(app(DirectoryCommands::class))->toBeInstanceOf(EloquentDirectoryCommands::class)
        ->and(app(DirectoryAdminContract::class))->toBeInstanceOf(EloquentDirectoryAdmin::class)
        ->and(app(DirectoryReportSubjectProvider::class))->toBeInstanceOf(DirectoryReportSubjectResolver::class)
        ->and(app(ReportSubjectResolver::class))->toBeInstanceOf(CompositeReportSubjectResolver::class)
        ->and(app(SubmissionEligibilitySync::class))->toBeInstanceOf(PublicSubmissionEligibilitySync::class);
});

it('resolves registered morph aliases and rejects unknown ones', function (): void {
    expect(MorphModelResolver::resolve('institution'))->toBe(Institution::class)
        ->and(MorphModelResolver::resolve('person'))->toBe(Person::class)
        ->and(MorphModelResolver::resolve('reference'))->toBe(Reference::class)
        ->and(MorphModelResolver::resolve('venue'))->toBe(Venue::class)
        ->and(MorphModelResolver::resolve('donation_channel'))->toBe(DonationChannel::class)
        ->and(fn () => MorphModelResolver::resolve('not_a_registered_alias'))->toThrow(RuntimeException::class);
});

it('reads directory details by id or slug and serves form options', function (): void {
    $institution = Institution::factory()->create(['name' => 'Masjid Ilmu']);
    $person = Person::factory()->create(['name' => 'Ahmad Ilmu']);

    $reader = app(DirectoryReader::class);
    $institutionId = (string) $institution->getKey();

    $byId = $reader->find(DirectoryRecordType::Institution, $institutionId);
    $bySlug = $reader->find(DirectoryRecordType::Institution, (string) $institution->slug);
    $personDetail = $reader->find(DirectoryRecordType::Person, (string) $person->getKey());

    expect($byId?->title)->toBe('Masjid Ilmu')
        ->and($byId?->publicUrl)->toBe(route('institutions.show', $institution))
        ->and($bySlug?->id)->toBe($institutionId)
        ->and($personDetail?->title)->toBe($person->formatted_name)
        ->and($reader->exists(DirectoryRecordType::Institution, $institutionId))->toBeTrue()
        ->and($reader->find(DirectoryRecordType::Institution, 'missing-record'))->toBeNull();

    $options = collect($reader->options(DirectoryRecordType::Institution, 'Masjid Ilmu'))->keyBy('id');

    expect($options->has($institutionId))->toBeTrue()
        ->and($options->get($institutionId)?->label)->toBe($institution->display_name);
});

it('reads reference details and options through the directory reader', function (): void {
    $reference = Reference::factory()->create([
        'title' => 'Kitab Seam',
        'slug' => 'kitab-seam',
        'status' => 'verified',
    ]);

    $reader = app(DirectoryReader::class);
    $detail = $reader->find(DirectoryRecordType::Reference, (string) $reference->slug);
    $options = collect($reader->options(DirectoryRecordType::Reference, 'Kitab Seam'))->keyBy('id');

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

it('writes directory records through the command boundary', function (): void {
    $actor = User::factory()->create();
    $commands = app(DirectoryCommands::class);

    $created = $commands->create(DirectoryRecordType::Institution, [
        'name' => 'Masjid Perintah',
        'type' => 'masjid',
    ], (string) $actor->getKey());

    $updated = $commands->update(
        DirectoryRecordType::Institution,
        $created->id,
        ['name' => 'Masjid Diperbaharui'],
        (string) $actor->getKey(),
    );

    $verified = $commands->changeStatus(
        DirectoryRecordType::Institution,
        $created->id,
        'verified',
        (string) $actor->getKey(),
    );

    expect($created->type)->toBe(DirectoryRecordType::Institution)
        ->and($updated->title)->toContain('Masjid Diperbaharui')
        ->and($verified->status)->toBe('verified');

    $commands->syncRelations(DirectoryRecordType::Institution, $created->id, []);

    $commands->delete(DirectoryRecordType::Institution, $created->id, (string) $actor->getKey());

    expect(Institution::query()->find($created->id))->toBeNull();
});

it('applies a contribution payload without changing the record lifecycle', function (): void {
    $person = Person::factory()->create(['name' => 'Nama Asal']);

    $changes = app(DirectoryCommands::class)->apply(
        DirectoryRecordType::Person,
        (string) $person->getKey(),
        ['name' => 'Nama Baharu'],
    );

    expect($changes)->toHaveKey('name')
        ->and($person->fresh()?->name)->toBe('Nama Baharu');
});

it('exposes directory admin schema, defaults and record snapshots', function (): void {
    $institution = Institution::factory()->create(['name' => 'Masjid Admin']);
    $admin = app(DirectoryAdminContract::class);

    $schema = $admin->schema(DirectoryRecordType::Institution);
    $defaults = $admin->defaults(DirectoryRecordType::Institution);
    $record = $admin->record(DirectoryRecordType::Institution, (string) $institution->getKey());

    expect($admin->resourceKey(DirectoryRecordType::Institution))->toBe('institutions')
        ->and($schema->resourceKey)->toBe('institutions')
        ->and($schema->operation)->toBe('create')
        ->and($schema->fields)->not->toBeEmpty()
        ->and($schema->defaults)->toBeArray()
        ->and($defaults)->toBeArray()
        ->and($record?->id)->toBe((string) $institution->getKey())
        ->and($record?->attributes['name'] ?? null)->toBe('Masjid Admin');
});

it('lists current directory records as scalar options', function (): void {
    $institution = Institution::factory()->create(['name' => 'Masjid Ujian']);
    $person = Person::factory()->create(['name' => 'Ahmad Ujian']);

    $catalog = app(DirectoryCatalog::class);
    $institutionId = (string) $institution->getKey();
    $personId = (string) $person->getKey();

    $institutions = collect($catalog->institutions())->keyBy('id');
    $persons = collect($catalog->persons())->keyBy('id');

    expect($institutions->has($institutionId))->toBeTrue()
        ->and($institutions->get($institutionId)?->label)->toBe('Masjid Ujian')
        ->and($persons->has($personId))->toBeTrue()
        ->and($persons->get($personId)?->label)->toBe('Ahmad Ujian');
});

it('syncs locked directory entities for the account when eligibility fails', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create();
    $person = Person::factory()->create();

    addTestMember($institution, $user, MemberRole::Viewer);
    addTestMember($person, $user, MemberRole::Viewer);

    $institution->forceFill(['allow_public_event_submission' => false])->save();
    $person->forceFill(['allow_public_event_submission' => false])->save();

    app(SubmissionEligibilitySync::class)->syncForAccount((string) $user->getKey());

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

    app(SubmissionEligibilitySync::class)->syncInstitution((string) $institution->getKey());

    expect($institution->fresh()?->allow_public_event_submission)->toBeFalse();
});
