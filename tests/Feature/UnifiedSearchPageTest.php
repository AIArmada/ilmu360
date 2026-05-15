<?php

use App\Enums\EventFormat;
use App\Enums\ReferenceType;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Support\Search\InstitutionSearchService;
use App\Support\Search\SpeakerSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('submits the homepage hero search to the unified search page', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('action="'.route('search.index').'"', false);
});

it('shows grouped event speaker reference and institution matches on the unified search page', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $institution = Institution::factory()->create([
        'name' => 'Masjid Nur Hikmah',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $speaker = Speaker::factory()->create([
        'name' => 'Ustaz Nur Hikmah',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $speaker->addMedia(UploadedFile::fake()->image('speaker.jpg', 1200, 1200))
        ->toMediaCollection('avatar');

    $event = Event::factory()
        ->for($institution)
        ->create([
            'title' => 'Kuliah Nur Hikmah',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDay(),
            'event_format' => EventFormat::Physical,
            'is_active' => true,
        ]);

    $reference = Reference::factory()->create([
        'title' => 'Nur Hikmah: Adab Menuntut Ilmu',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $this->get(route('search.index', ['search' => 'Nur Hikmah']))
        ->assertOk()
        ->assertSee('Kuliah Nur Hikmah')
        ->assertSee('Ustaz Nur Hikmah')
        ->assertSee('Nur Hikmah: Adab Menuntut Ilmu')
        ->assertSee('Masjid Nur Hikmah')
        ->assertSee($speaker->public_avatar_url, false)
        ->assertSee(route('events.show', $event), false)
        ->assertSee(route('speakers.show', $speaker), false)
        ->assertSee(route('references.show', $reference), false)
        ->assertSee(route('institutions.show', $institution), false);
});

it('falls back to local speaker and institution search on the unified search page when typesense fails', function () {
    $institution = Institution::factory()->create([
        'name' => 'Masjid Nur Hikmah',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $speaker = Speaker::factory()->create([
        'name' => 'Nur Hikmah Hassan',
        'honorific' => null,
        'pre_nominal' => [],
        'post_nominal' => [],
        'qualifications' => [],
        'status' => 'verified',
        'is_active' => true,
    ]);

    app(SpeakerSearchService::class)->syncSpeakerRecord($speaker);
    config()->set('scout.driver', 'typesense');

    $this->app->bind(SpeakerSearchService::class, fn (): SpeakerSearchService => new class extends SpeakerSearchService
    {
        protected function shouldUseScoutSearch(): bool
        {
            return true;
        }

        protected function searchIdsWithScout(string $search, array $options = []): array
        {
            throw new RuntimeException('Typesense unavailable');
        }

        protected function logScoutFallback(string $message, Throwable $exception, string $search): void {}
    });

    $this->app->bind(InstitutionSearchService::class, fn (): InstitutionSearchService => new class extends InstitutionSearchService
    {
        protected function shouldUseScoutSearch(): bool
        {
            return true;
        }

        protected function searchIdsWithScout(string $search, array $options = []): array
        {
            throw new RuntimeException('Typesense unavailable');
        }

        protected function logScoutFallback(string $message, Throwable $exception, string $search): void {}
    });

    $this->get(route('search.index', ['search' => 'Nur Hikmah']))
        ->assertOk()
        ->assertSee('Nur Hikmah Hassan')
        ->assertSee('Masjid Nur Hikmah');
});

it('shows nearby event matches on the unified search page when location is present', function () {
    $institution = Institution::factory()->create([
        'name' => 'Masjid Taman Setia',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $institution->addressModel?->update([
        'lat' => 3.1390,
        'lng' => 101.6869,
    ]);

    Event::factory()
        ->for($institution)
        ->create([
            'title' => 'Kuliah Berdekatan',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDay(),
            'event_format' => EventFormat::Physical,
            'is_active' => true,
        ]);

    $this->get(route('search.index', [
        'lat' => '3.1390',
        'lng' => '101.6869',
    ]))
        ->assertOk()
        ->assertSee('Kuliah Berdekatan')
        ->assertSee(__('Nearby events'));
});

it('uses a 16:9 placeholder aspect ratio on unified search event cards without posters', function () {
    $institution = Institution::factory()->create([
        'name' => 'Masjid Carian Tanpa Poster',
        'status' => 'verified',
        'is_active' => true,
    ]);

    Event::factory()
        ->for($institution)
        ->create([
            'title' => 'Kuliah Carian Tanpa Poster',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDay(),
            'event_format' => EventFormat::Physical,
            'is_active' => true,
        ]);

    $this->get(route('search.index', ['search' => 'Carian Tanpa Poster']))
        ->assertOk()
        ->assertSee('Kuliah Carian Tanpa Poster')
        ->assertSee('data-cover-aspect="16:9"', false);
});

it('renders the book title only on book-backed search result cards without parentheses', function () {
    $institution = Institution::factory()->create([
        'name' => 'Masjid Carian Kitab',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $bookEvent = Event::factory()
        ->for($institution)
        ->create([
            'title' => 'Fiqh Carian Kitab',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDay(),
            'event_format' => EventFormat::Physical,
            'is_active' => true,
        ]);

    $articleEvent = Event::factory()
        ->for($institution)
        ->create([
            'title' => 'Fiqh Carian Artikel',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDays(2),
            'event_format' => EventFormat::Physical,
            'is_active' => true,
        ]);

    $bookReference = Reference::factory()->create([
        'title' => 'Matan Abi Syuja',
        'type' => ReferenceType::Book->value,
    ]);

    $articleReference = Reference::factory()->create([
        'title' => 'Makalah Fiqh Kontemporari',
        'type' => ReferenceType::Article->value,
    ]);

    $bookEvent->references()->attach($bookReference->id);
    $articleEvent->references()->attach($articleReference->id);

    $response = $this->get(route('search.index', ['search' => 'Fiqh Carian']))
        ->assertOk();

    $html = $response->getContent();

    expect($html)
        ->toContain('Fiqh Carian Kitab')
        ->toContain('Fiqh Carian Artikel')
        ->toContain('Matan Abi Syuja')
        ->not->toContain('(Matan Abi Syuja)')
        ->and(substr_count((string) $html, 'Matan Abi Syuja'))->toBe(1);
});
