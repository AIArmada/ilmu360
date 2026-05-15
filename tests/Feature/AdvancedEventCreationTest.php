<?php

use App\Filament\Ahli\Resources\Events\EventResource as AhliEventResource;
use App\Livewire\Pages\Dashboard\Events\CreateAdvanced;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('forbids the advanced builder for authenticated users without member entities', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard.events.create-advanced'))
        ->assertForbidden();
});

it('creates a parent program draft then redirects into child-event submission', function () {
    $user = User::factory()->create(['name' => 'Aisyah Member']);
    $institution = Institution::factory()->create();

    $institution->members()->syncWithoutDetaching([$user->id]);

    $this->actingAs($user)
        ->get(AhliEventResource::getUrl('index', panel: 'ahli'))
        ->assertSuccessful()
        ->assertSee('Create Advanced Program');

    Livewire::actingAs($user)
        ->test(CreateAdvanced::class)
        ->set('form.title', 'Ramadan Knowledge Series')
        ->set('form.description', 'A month-long umbrella program.')
        ->set('form.program_starts_at', now()->addDays(2)->setTime(20, 0)->format('Y-m-d\TH:i'))
        ->set('form.program_ends_at', now()->addDays(30)->setTime(22, 0)->format('Y-m-d\TH:i'))
        ->set('form.organizer_type', 'institution')
        ->set('form.organizer_id', $institution->id)
        ->set('form.default_event_type', 'kuliah_ceramah')
        ->set('form.default_event_format', 'physical')
        ->call('submit')
        ->assertHasNoErrors();

    $parentEvent = Event::query()
        ->where('title', 'Ramadan Knowledge Series')
        ->first();

    expect($parentEvent)->not->toBeNull()
        ->and($parentEvent?->isParentProgram())->toBeTrue()
        ->and((string) $parentEvent?->status)->toBe('draft')
        ->and($parentEvent?->childEvents()->count())->toBe(0)
        ->and($parentEvent?->settings?->registration_required)->toBeFalse();

    $redirectComponent = Livewire::actingAs($user)
        ->test(CreateAdvanced::class)
        ->set('form.title', 'Another Parent Program')
        ->set('form.program_starts_at', now()->addDays(5)->setTime(20, 0)->format('Y-m-d\TH:i'))
        ->set('form.program_ends_at', now()->addDays(10)->setTime(22, 0)->format('Y-m-d\TH:i'))
        ->set('form.organizer_type', 'institution')
        ->set('form.organizer_id', $institution->id)
        ->call('submit')
        ->assertHasNoErrors();

    $redirectParent = Event::query()->where('title', 'Another Parent Program')->firstOrFail();

    $redirectComponent->assertRedirect(route('submit-event.create', ['parent' => $redirectParent->id]));
});

it('offers parent templates and a normalized child-submission workflow', function () {
    $user = User::factory()->create(['name' => 'Planner Member']);
    $institution = Institution::factory()->create(['name' => 'Masjid Perancang']);

    $institution->members()->syncWithoutDetaching([$user->id]);

    $this->actingAs($user)
        ->get(route('dashboard.events.create-advanced'))
        ->assertSuccessful()
        ->assertSee('Create the parent first')
        ->assertSee('Weekly Series')
        ->assertSee('Standard submit-event UI');

    $component = Livewire::actingAs($user)
        ->test(CreateAdvanced::class)
        ->call('applyTemplate', 'weekly_series');

    expect($component->get('activeStep'))->toBe(2)
        ->and($component->get('form')['title'])->toBe('Weekly Knowledge Series')
        ->and(filled($component->get('form')['program_starts_at']))->toBeTrue()
        ->and(filled($component->get('form')['program_ends_at']))->toBeTrue();
});

it('prefills the institution when launched from the institution dashboard shortcut', function () {
    $user = User::factory()->create(['name' => 'Institution Shortcut Member']);
    $institution = Institution::factory()->create(['name' => 'Masjid Pintasan']);
    $otherInstitution = Institution::factory()->create(['name' => 'Masjid Lain']);

    $institution->members()->syncWithoutDetaching([$user->id]);
    $otherInstitution->members()->syncWithoutDetaching([$user->id]);

    $component = Livewire::withQueryParams(['institution' => $institution->id])
        ->actingAs($user)
        ->test(CreateAdvanced::class);

    expect($component->get('form')['organizer_type'])->toBe('institution')
        ->and($component->get('form')['organizer_id'])->toBe($institution->id)
        ->and($component->get('form')['location_institution_id'])->toBe($institution->id)
        ->and($component->get('form')['registration_required'])->toBeFalse();
});

it('shows a validation error when the parent program ends before it starts', function () {
    $user = User::factory()->create(['name' => 'Validation Member']);
    $institution = Institution::factory()->create(['name' => 'Masjid Validasi']);

    $institution->members()->syncWithoutDetaching([$user->id]);

    Livewire::actingAs($user)
        ->test(CreateAdvanced::class)
        ->set('form.title', 'Invalid Parent Program')
        ->set('form.program_starts_at', now()->addDays(5)->setTime(20, 0)->format('Y-m-d\TH:i'))
        ->set('form.program_ends_at', now()->addDays(4)->setTime(20, 0)->format('Y-m-d\TH:i'))
        ->set('form.organizer_type', 'institution')
        ->set('form.organizer_id', $institution->id)
        ->call('submit')
        ->assertHasErrors(['form.program_ends_at']);

    expect(Event::query()->where('title', 'Invalid Parent Program')->exists())->toBeFalse();
});

it('includes the organizer speaker slug when creating a parent program for a speaker organizer', function () {
    $user = User::factory()->create(['name' => 'Speaker Organizer Member']);
    $speaker = Speaker::factory()->create([
        'name' => 'Habib Umar',
        'slug' => 'habib-umar',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $speaker->members()->syncWithoutDetaching([$user->id]);

    $programStartsAt = now()->addDays(2)->setTime(20, 0);

    Livewire::actingAs($user)
        ->test(CreateAdvanced::class)
        ->set('form.title', 'Speaker Parent Program')
        ->set('form.description', 'Speaker-led umbrella program.')
        ->set('form.program_starts_at', $programStartsAt->format('Y-m-d\TH:i'))
        ->set('form.program_ends_at', now()->addDays(10)->setTime(22, 0)->format('Y-m-d\TH:i'))
        ->set('form.organizer_type', 'speaker')
        ->set('form.organizer_id', $speaker->id)
        ->set('form.default_event_type', 'kuliah_ceramah')
        ->set('form.default_event_format', 'physical')
        ->call('submit')
        ->assertHasNoErrors();

    $parentEvent = Event::query()->where('title', 'Speaker Parent Program')->firstOrFail();
    $expectedSuffix = $parentEvent->starts_at?->copy()->timezone('Asia/Kuala_Lumpur')->format('j-n-y');

    expect($parentEvent->slug)->toBe("speaker-parent-program-{$speaker->slug}-{$expectedSuffix}");
});
