<?php

use App\Filament\Resources\Series\Pages\EditSeries;
use App\Filament\Resources\Spaces\Pages\EditSpace;
use App\Models\Affiliation;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Language;
use App\Models\Person;
use App\Models\Series;
use App\Models\Space;
use App\Models\User;
use App\Services\ContributionEntityMutationService;
use App\Support\Auditing\AuditValuePresenter;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OwenIt\Auditing\AuditableObserver;

beforeEach(function () {
    config()->set('audit.console', true);
    config()->set('media-library.disk_name', 'public');
    config()->set('media-library.queue_connection_name', 'database');
    config()->set('media-library.queue_conversions_by_default', true);

    Storage::fake('public');
    Queue::fake();

    Event::observe(AuditableObserver::class);
    Institution::observe(AuditableObserver::class);
    Person::observe(AuditableObserver::class);
    Series::observe(AuditableObserver::class);
    Space::observe(AuditableObserver::class);

    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);
});

it('records media collection changes on audited owners', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $event = Event::factory()->create();

    $this->actingAs($administrator);

    $event->addMedia(UploadedFile::fake()->image('poster.jpg', 1200, 800))
        ->toMediaCollection('poster');

    $media = $event->getFirstMedia('poster');
    $audit = $event->audits()
        ->where('event', 'media_created')
        ->latest('created_at')
        ->first();

    expect($media)->not->toBeNull()
        ->and($audit)->not->toBeNull()
        ->and($audit?->user_id)->toBe($administrator->id)
        ->and($audit?->old_values['poster_media'] ?? null)->toBe([])
        ->and($audit?->new_values['poster_media'][0]['id'] ?? null)->toBe((string) $media?->getKey())
        ->and($audit?->new_values['poster_media'][0]['file_name'] ?? null)->toBe($media?->file_name);
});

it('records series language syncs performed by the filament edit page', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $series = Series::factory()->create([
        'visibility' => 'public',
    ]);

    $english = Language::query()->firstWhere('code', 'en') ?? Language::query()->create([
        'code' => 'en',
        'name' => 'English',
        'native' => 'English',
        'dir' => 'ltr',
    ]);
    $malay = Language::query()->firstWhere('code', 'ms') ?? Language::query()->create([
        'code' => 'ms',
        'name' => 'Malay',
        'native' => 'Bahasa Melayu',
        'dir' => 'ltr',
    ]);

    $series->languages()->sync([$english->getKey()]);

    Livewire::actingAs($administrator)
        ->test(EditSeries::class, ['record' => $series->getKey()])
        ->fillForm([
            'title' => $series->title,
            'slug' => $series->slug,
            'description' => $series->description,
            'visibility' => $series->visibility,
            'status' => $series->status,
            'languages' => [$english->getKey(), $malay->getKey()],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $audit = $series->audits()
        ->where('event', 'relations_updated')
        ->latest('created_at')
        ->first();
    $oldLanguages = $audit?->old_values['languages'] ?? [];
    $newLanguages = $audit?->new_values['languages'] ?? [];

    expect($series->fresh()->languages()->pluck('languages.id')->all())
        ->toEqualCanonicalizing([$english->getKey(), $malay->getKey()])
        ->and($audit)->not->toBeNull()
        ->and($oldLanguages)->toHaveCount(1)
        ->and($oldLanguages[0]['id'] ?? null)->toBe($english->getKey())
        ->and($oldLanguages[0]['name'] ?? null)->toBe($english->name)
        ->and($newLanguages)->toHaveCount(2)
        ->and(collect($newLanguages)->pluck('id')->all())->toEqual([$english->getKey(), $malay->getKey()]);
});

it('records space institution syncs performed by the filament edit page', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $space = Space::factory()->create();
    $alphaInstitution = Institution::factory()->create([
        'name' => 'Alpha Mosque',
        'slug' => 'alpha-mosque',
    ]);
    $betaInstitution = Institution::factory()->create([
        'name' => 'Beta Mosque',
        'slug' => 'beta-mosque',
    ]);

    $space->institutions()->sync([$alphaInstitution->getKey()]);

    Livewire::actingAs($administrator)
        ->test(EditSpace::class, ['record' => $space->getKey()])
        ->fillForm([
            'name' => $space->name,
            'slug' => $space->slug,
            'capacity' => $space->capacity,
            'status' => $space->status,
            'institutions' => [(string) $alphaInstitution->getKey(), (string) $betaInstitution->getKey()],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $audit = $space->audits()
        ->where('event', 'relations_updated')
        ->latest('created_at')
        ->first();
    $oldInstitutions = $audit?->old_values['institutions'] ?? [];
    $newInstitutions = $audit?->new_values['institutions'] ?? [];
    $presentedValues = $audit === null ? [] : AuditValuePresenter::values($audit, 'new_values');

    expect($space->fresh()->institutions()->pluck('institutions.id')->all())
        ->toEqualCanonicalizing([(string) $alphaInstitution->getKey(), (string) $betaInstitution->getKey()])
        ->and($audit)->not->toBeNull()
        ->and($oldInstitutions)->toHaveCount(1)
        ->and($oldInstitutions[0]['id'] ?? null)->toBe((string) $alphaInstitution->getKey())
        ->and($oldInstitutions[0]['name'] ?? null)->toBe($alphaInstitution->name)
        ->and($newInstitutions)->toHaveCount(2)
        ->and(collect($newInstitutions)->pluck('id')->all())->toEqual([(string) $alphaInstitution->getKey(), (string) $betaInstitution->getKey()])
        ->and($presentedValues['Institutions'] ?? null)->toBe('Alpha Mosque, Beta Mosque');
});

it('records membership sync and role change audits on auditable subjects', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $institution = Institution::factory()->create();
    $member = User::factory()->create([
        'phone' => '+60112223344',
        'phone_verified_at' => now(),
    ]);

    $this->actingAs($administrator);

    addTestMember($institution, $member, 'viewer');

    $syncAudit = $institution->audits()
        ->where('event', 'sync')
        ->latest('created_at')
        ->first();
    $roleAudit = $institution->audits()
        ->where('event', 'member_role_changed')
        ->latest('created_at')
        ->first();
    $addedMembers = $syncAudit?->new_values['members'] ?? [];

    expect($institution->fresh()->members()->whereKey($member->getKey())->exists())->toBeTrue()
        ->and($syncAudit)->not->toBeNull()
        ->and($syncAudit?->user_id)->toBe($administrator->id)
        ->and($addedMembers)->toHaveCount(1)
        ->and($addedMembers[0]['id'] ?? null)->toBe($member->getKey())
        ->and($addedMembers[0]['name'] ?? null)->toBe($member->name)
        ->and($roleAudit)->not->toBeNull()
        ->and($roleAudit?->user_id)->toBe($administrator->id)
        ->and($roleAudit?->new_values['member_role']['user_id'] ?? null)->toBe($member->getKey())
        ->and($roleAudit?->new_values['member_role']['role'] ?? null)->toBe('viewer');
});

it('records person affiliation sync audits on auditable subjects', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $currentInstitution = Institution::factory()->create();
    $secondaryInstitution = Institution::factory()->create();
    $newInstitution = Institution::factory()->create();
    $person = Person::factory()->create();

    $person->institutions()->detach();

    Affiliation::create([
        'affiliatable_type' => $person->getMorphClass(),
        'affiliatable_id' => $person->getKey(),
        'institution_id' => $currentInstitution->id,
        'position' => 'Imam',
        'is_primary' => true,
    ]);
    Affiliation::create([
        'affiliatable_type' => $person->getMorphClass(),
        'affiliatable_id' => $person->getKey(),
        'institution_id' => $secondaryInstitution->id,
        'position' => 'Advisor',
        'is_primary' => false,
    ]);

    $this->actingAs($administrator);

    app(ContributionEntityMutationService::class)->syncPersonRelations($person, [
        'institution_id' => $newInstitution->id,
        'institution_position' => 'Mudir',
    ]);

    $person = $person->fresh('institutions');
    $syncAudit = $person?->audits()
        ->where('event', 'sync')
        ->latest('created_at')
        ->first();
    $oldInstitutions = $syncAudit?->old_values['institutions'] ?? [];
    $newInstitutions = $syncAudit?->new_values['institutions'] ?? [];
    $presentedValues = $syncAudit === null ? [] : AuditValuePresenter::values($syncAudit, 'new_values');

    $newAffiliation = $person?->institutions->firstWhere('id', $newInstitution->id);
    $secondaryAffiliation = $person?->institutions->firstWhere('id', $secondaryInstitution->id);

    expect($person?->institutions->pluck('id')->all())->toEqualCanonicalizing([
        $newInstitution->getKey(),
        $secondaryInstitution->getKey(),
    ])
        ->and($person?->institutions->firstWhere('id', $currentInstitution->id))->toBeNull()
        ->and($newAffiliation)->not->toBeNull()
        ->and($newAffiliation?->pivot?->position)->toBe('Mudir')
        ->and((bool) $newAffiliation?->pivot?->is_primary)->toBeTrue()
        ->and((bool) $secondaryAffiliation?->pivot?->is_primary)->toBeFalse()
        ->and($syncAudit)->not->toBeNull()
        ->and($syncAudit?->event)->toBe('sync')
        ->and($syncAudit?->user_id)->toBe($administrator->id)
        ->and($oldInstitutions)->toHaveCount(2)
        ->and($oldInstitutions[0]['id'] ?? null)->toBe($currentInstitution->id)
        ->and($oldInstitutions[0]['name'] ?? null)->toBe($currentInstitution->name)
        ->and($newInstitutions)->toHaveCount(2)
        ->and($newInstitutions[0]['id'] ?? null)->toBe($newInstitution->id)
        ->and($newInstitutions[0]['name'] ?? null)->toBe($newInstitution->name)
        ->and($presentedValues['Institutions'] ?? null)->toBe($newInstitution->name.', '.$secondaryInstitution->name);
});

it('records person affiliation pivot updates when the institution is already attached', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $institution = Institution::factory()->create();
    $person = Person::factory()->create();

    $person->institutions()->detach();
    Affiliation::create([
        'affiliatable_type' => $person->getMorphClass(),
        'affiliatable_id' => $person->getKey(),
        'institution_id' => $institution->id,
        'position' => 'Imam',
        'is_primary' => true,
    ]);

    $this->actingAs($administrator);

    app(ContributionEntityMutationService::class)->syncPersonRelations($person, [
        'institution_id' => $institution->id,
        'institution_position' => 'Mudir',
    ]);

    $person = $person->fresh('institutions');
    $syncAudit = $person?->audits()
        ->where('event', 'sync')
        ->latest('created_at')
        ->first();

    expect($syncAudit)->not->toBeNull()
        ->and($syncAudit?->user_id)->toBe($administrator->id)
        ->and($syncAudit?->old_values['institutions'][0]['position'] ?? null)->toBe('Imam')
        ->and($syncAudit?->new_values['institutions'][0]['position'] ?? null)->toBe('Mudir')
        ->and($person?->institutions->firstWhere('id', $institution->id)?->pivot?->position)->toBe('Mudir')
        ->and((bool) $person?->institutions->firstWhere('id', $institution->id)?->pivot?->is_primary)->toBeTrue();
});
