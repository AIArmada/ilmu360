<?php

use AIArmada\Events\Actions\CreateEventOccurrenceAction;
use App\Actions\References\SaveReferenceAction;
use App\Enums\ReferenceType;
use App\Models\Event;
use App\Models\Reference;
use App\Observers\ReferenceObserver;
use Database\Seeders\LanguageSeeder;
use Illuminate\Validation\ValidationException;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;

beforeEach(function (): void {
    config()->set('scout.driver', 'null');
});

it('identifies editions and their parts and expands each selected subtree', function (): void {
    $work = Reference::factory()->create(['title' => 'Riyadhus Solihin', 'type' => ReferenceType::Book->value]);
    $edition = Reference::factory()->edition()->create([
        'title' => $work->title, 'slug' => '', 'parent_id' => $work->id,
        'edition_number' => 3, 'publisher' => 'Dar al-Kutub', 'year' => 2018,
    ]);
    $part = Reference::factory()->part()->create([
        'title' => $work->title, 'slug' => '', 'parent_id' => $edition->id, 'part_number' => '2',
    ]);
    $otherEdition = Reference::factory()->edition()->create(['parent_id' => $work->id]);
    $unknownEditionPart = Reference::factory()->part()->create(['parent_id' => $work->id]);

    expect($edition->displayTitle())->toBe('Riyadhus Solihin — Cetakan 3 (Dar al-Kutub, 2018)')
        ->and($part->displayTitle())->toBe('Riyadhus Solihin — Cetakan 3 (Dar al-Kutub, 2018) — Jilid 2')
        ->and($part->slug)->toBe('riyadhus-solihin-cetakan-3-dar-al-kutub-2018-jilid-2')
        ->and($part->familyRootId())->toBe($work->id)
        ->and($work->defaultEventReferenceIds())->toEqualCanonicalizing([$work->id, $edition->id, $part->id, $otherEdition->id, $unknownEditionPart->id])
        ->and($edition->defaultEventReferenceIds())->toEqualCanonicalizing([$edition->id, $part->id])
        ->and($part->defaultEventReferenceIds())->toBe([$part->id]);
});

it('rejects invalid hierarchy changes without orphaning children', function (): void {
    $work = Reference::factory()->create(['type' => 'book']);
    $edition = Reference::factory()->edition()->create(['parent_id' => $work->id]);
    $part = Reference::factory()->part()->create(['parent_id' => $edition->id]);

    expect(fn () => $edition->update(['record_kind' => 'part', 'part_type' => 'jilid', 'part_number' => '3']))
        ->toThrow(ValidationException::class);
    expect(fn () => $work->update(['type' => 'article']))->toThrow(ValidationException::class);
    expect(fn () => Reference::factory()->edition()->create(['parent_id' => $part->id]))->toThrow(ValidationException::class);
    expect(fn () => Reference::factory()->part()->create(['parent_id' => $part->id]))->toThrow(ValidationException::class);
});

it('persists bibliographic fields with normalized valid ISBNs through the save boundary', function (string $isbn, string $normalized): void {
    $this->seed(LanguageSeeder::class);

    $reference = app(SaveReferenceAction::class)->handle([
        'title' => 'Kitab Ujian', 'type' => 'book', 'record_kind' => 'work',
        'isbn' => $isbn, 'language' => 'ar', 'url' => 'https://example.com/book',
        'publication_year' => '2020', 'status' => 'pending',
    ]);

    expect($reference->isbn)->toBe($normalized)
        ->and($reference->year)->toBe(2020)
        ->and($reference->language)->toBe('ar')
        ->and($reference->url)->toBe('https://example.com/book')
        ->and($reference->socialProfiles)->toHaveCount(0)
        ->and($reference->isPubliclyVisible())->toBeTrue();
})->with([
    ['978-0-306-40615-7', '9780306406157'],
    ['0 8044 2957 x', '080442957X'],
]);

it('rejects invalid bibliographic input as field validation errors', function (string $field, mixed $value): void {
    try {
        app(SaveReferenceAction::class)->handle(['title' => 'Invalid book', 'type' => 'book', $field => $value]);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    ['isbn', '9780306406158'],
    ['language', 'language-too-long'],
    ['url', 'ftp://example.com/book'],
    ['publication_year', 'not-a-year'],
    ['edition_number', 0],
]);

it('shows edition parts and their events on the work detail page', function (): void {
    $work = Reference::factory()->create(['title' => 'Kitab Keluarga', 'type' => 'book']);
    $edition = Reference::factory()->edition()->create(['title' => $work->title, 'parent_id' => $work->id, 'edition_label' => 'Edisi Semakan']);
    $part = Reference::factory()->part()->create(['title' => $work->title, 'parent_id' => $edition->id, 'part_number' => '2']);
    $event = Event::factory()->create(['title' => 'Kuliah Edisi Semakan', 'status' => 'approved', 'visibility' => 'public', 'published_at' => now(), 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour()]);
    $part->events()->attach($event);
    app(CreateEventOccurrenceAction::class)->handle($event, [
        'title' => $event->title,
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHour(),
        'timezone' => 'Asia/Kuala_Lumpur',
        'status' => 'published',
        'visibility' => 'public',
        'delivery_mode' => 'physical',
    ]);

    $this->get(route('references.show', $work))->assertOk()->assertSee('Edisi Semakan')->assertSee('Jilid 2')->assertSee('Kuliah Edisi Semakan');
});

it('refreshes part search identities after edition metadata changes', function (): void {
    $work = Reference::factory()->create(['title' => 'Kitab Carian', 'type' => 'book']);
    $edition = Reference::factory()->edition()->create(['title' => $work->title, 'parent_id' => $work->id, 'edition_label' => 'Edisi Lama']);
    $part = Reference::factory()->part()->create(['title' => $work->title, 'slug' => '', 'parent_id' => $edition->id, 'part_number' => '2']);
    $edition->update(['edition_label' => 'Edisi Baharu']);

    $engine = Mockery::mock(NullEngine::class)->makePartial();
    $engine->shouldReceive('update')->once()->withArgs(function ($models) use ($part): bool {
        return $models->count() === 1
            && $models->first()->getKey() === $part->getKey()
            && str_contains($models->first()->toSearchableArray()['display_title'], 'Edisi Baharu');
    });
    app(EngineManager::class)->extend('reference-test', fn () => $engine);
    config()->set('scout.driver', 'reference-test');
    config()->set('scout.queue', false);

    app(ReferenceObserver::class)->updated($edition);

    expect($part->fresh()->slug)->toContain('edisi-baharu');
});
