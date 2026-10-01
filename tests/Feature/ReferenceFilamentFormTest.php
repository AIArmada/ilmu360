<?php

use AIArmada\Signals\Models\SignalEvent;
use App\Enums\ReferencePartType;
use App\Enums\ReferenceType;
use App\Filament\Resources\References\Pages\CreateReference;
use App\Filament\Resources\References\Pages\EditReference;
use App\Forms\ReferenceFormSchema;
use App\Models\Reference;
use App\Models\User;
use App\Support\Cache\SelectionCatalogCache;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('persists a selected parent book through the direct Filament reference form', function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $parent = Reference::factory()->create([
        'title' => 'Root Book',
        'type' => ReferenceType::Book->value,
        'parent_id' => null,
    ]);

    Livewire::actingAs($administrator)
        ->test(CreateReference::class)
        ->fillForm([
            'title' => 'Root Book Volume Two',
            'type' => ReferenceType::Book->value,
            'record_kind' => 'part',
            'parent_id' => $parent->id,
            'part_type' => ReferencePartType::Jilid->value,
            'part_number' => '2',
            'year' => '2024',
            'status' => 'verified',
            'socialProfiles' => [],
        ])
        ->call('create')
        ->assertHasNoErrors();

    $part = Reference::query()
        ->where('title', 'Root Book Volume Two')
        ->firstOrFail();

    expect($part->parent_id)->toBe($parent->id)
        ->and($part->partTypeValue())->toBe(ReferencePartType::Jilid->value)
        ->and($part->partNumberValue())->toBe('2')
        ->and($part->year)->toBe(2024);
});

it('persists edition bibliographic fields through the direct Filament reference form', function (): void {
    $this->seed(LanguageSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');
    $work = Reference::factory()->create(['title' => 'Edition Work', 'type' => 'book']);

    Livewire::actingAs($administrator)->test(CreateReference::class)
        ->fillForm([
            'title' => 'Edition Work', 'type' => 'book', 'record_kind' => 'edition',
            'parent_id' => $work->id, 'edition_number' => 3, 'edition_label' => 'Revised edition',
            'year' => 2024, 'publisher' => 'Sample Publisher', 'isbn' => '978-0-306-40615-7',
            'language' => 'en', 'url' => 'https://example.com/edition', 'status' => 'verified',
            'socialProfiles' => [],
        ])->call('create')->assertHasNoFormErrors();

    $edition = Reference::query()->where('parent_id', $work->id)->firstOrFail();
    expect($edition->record_kind)->toBe('edition')
        ->and($edition->edition_number)->toBe(3)
        ->and($edition->isbn)->toBe('9780306406157')
        ->and($edition->language)->toBe('en')
        ->and($edition->url)->toBe('https://example.com/edition');
});

it('quick creates a publicly pending part with bibliographic fields and one primary url', function (): void {
    $this->seed(LanguageSeeder::class);
    $work = Reference::factory()->create(['title' => 'Quick Work', 'type' => 'book']);
    $edition = Reference::factory()->create([
        'title' => 'Quick Work', 'type' => 'book', 'record_kind' => 'edition',
        'parent_id' => $work->id, 'edition_number' => 2,
    ]);

    $id = ReferenceFormSchema::createPending([
        'title' => 'Quick Work', 'type' => 'book', 'record_kind' => 'part', 'parent_id' => $edition->id,
        'part_type' => 'jilid', 'part_number' => '2', 'publication_year' => 2024,
        'publisher' => 'Sample Publisher', 'isbn' => '0-306-40615-2', 'language' => 'ms',
        'url' => 'https://example.com/part',
    ]);

    $part = Reference::query()->active()->findOrFail($id);
    expect($part->parent_id)->toBe($edition->id)
        ->and($part->status)->toBe('pending')
        ->and($part->published_at)->not->toBeNull()
        ->and($part->year)->toBe(2024)
        ->and($part->isbn)->toBe('0306406152')
        ->and($part->language)->toBe('ms')
        ->and($part->url)->toBe('https://example.com/part')
        ->and($part->socialProfiles()->count())->toBe(0);
});

it('rejects an invalid ISBN in the admin form before creating a reference', function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    Livewire::actingAs($administrator)->test(CreateReference::class)
        ->fillForm([
            'title' => 'Invalid ISBN Reference', 'type' => 'book', 'record_kind' => 'work',
            'isbn' => '9780306406158', 'status' => 'verified', 'socialProfiles' => [],
        ])->call('create')->assertHasFormErrors(['isbn']);

    $this->assertDatabaseMissing('references', ['title' => 'Invalid ISBN Reference']);
});

it('clears the parent when an admin changes a part into a work', function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');
    $work = Reference::factory()->create(['type' => 'book']);
    $part = Reference::factory()->create([
        'type' => 'book', 'record_kind' => 'part', 'parent_id' => $work->id,
        'part_type' => 'jilid', 'part_number' => '2',
    ]);

    Livewire::actingAs($administrator)->test(EditReference::class, ['record' => $part->getRouteKey()])
        ->fillForm(['record_kind' => 'work', 'parent_id' => null])
        ->call('save')->assertHasNoFormErrors();

    $part->refresh();
    expect($part->record_kind)->toBe('work')
        ->and($part->parent_id)->toBeNull()
        ->and($part->reference_parts)->toBeNull();
});

it('maps a hierarchy model rejection to the admin form field', function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');
    $work = Reference::factory()->create(['type' => 'book']);
    Reference::factory()->create([
        'title' => 'Child Edition', 'type' => 'book', 'record_kind' => 'edition',
        'parent_id' => $work->id, 'edition_number' => 2,
    ]);

    Livewire::actingAs($administrator)->test(EditReference::class, ['record' => $work->getRouteKey()])
        ->fillForm(['type' => 'article'])->call('save')->assertHasFormErrors(['type']);

    expect($work->fresh()->type)->toBe('book');
});

it('allows admins to save a part under an inactive parent', function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');
    $work = Reference::factory()->create(['type' => 'book', 'status' => 'inactive']);

    Livewire::actingAs($administrator)->test(CreateReference::class)
        ->fillForm([
            'title' => 'Inactive Parent Part', 'type' => 'book', 'record_kind' => 'part',
            'parent_id' => $work->id, 'part_type' => 'jilid', 'part_number' => '2',
            'status' => 'verified', 'socialProfiles' => [],
        ])->call('create')->assertHasNoFormErrors();

    $this->assertDatabaseHas('references', ['title' => 'Inactive Parent Part', 'parent_id' => $work->id]);
});

it('records a curated outcome when a pending edition is quick created', function (): void {
    $work = Reference::factory()->create(['type' => 'book']);

    $id = ReferenceFormSchema::createPending([
        'title' => 'Signal Edition', 'type' => 'book', 'record_kind' => 'edition',
        'parent_id' => $work->id, 'edition_number' => 2,
    ]);

    $signal = SignalEvent::query()->where('event_name', 'reference.quick_created')->sole();
    expect($signal->event_category)->toBe('contribution')
        ->and(data_get($signal->properties, 'reference_id'))->toBe($id)
        ->and(data_get($signal->properties, 'record_kind'))->toBe('edition')
        ->and(data_get($signal->properties, 'parent_id'))->toBe($work->id)
        ->and(data_get($signal->properties, 'status'))->toBe('pending')
        ->and(data_get($signal->properties, 'source'))->toBe('event_reference_picker');
});

it('does not record a quick creation outcome when reference input is invalid', function (): void {
    expect(fn (): string => ReferenceFormSchema::createPending([
        'title' => 'Invalid Quick Reference', 'type' => 'book', 'record_kind' => 'work', 'isbn' => '9780306406158',
    ]))->toThrow(ValidationException::class);

    expect(SignalEvent::query()->where('event_name', 'reference.quick_created')->exists())->toBeFalse();
    $this->assertDatabaseMissing('references', ['title' => 'Invalid Quick Reference']);
});

it('rejects malformed selected reference ids before querying options', function (): void {
    expect(ReferenceFormSchema::selectedLabels(['not-a-uuid', [], null]))->toBe([]);
});

it('previews the same localized part title that quick create saves', function (string $locale, string $partType, ?string $customName, string $expectedLabel): void {
    app()->setLocale($locale);
    $work = Reference::factory()->create(['title' => 'Riyadhus Solihin', 'type' => 'book']);
    $edition = Reference::factory()->edition()->create(['title' => $work->title, 'parent_id' => $work->id, 'edition_label' => 'Edisi Semakan', 'publisher' => null, 'year' => null]);
    $data = [
        'title' => $work->title, 'type' => 'book', 'record_kind' => 'part',
        'parent_id' => $edition->id, 'part_type' => $partType,
        'part_number' => '2', 'part_label' => $customName,
    ];

    $preview = ReferenceFormSchema::previewTitle($data);
    $reference = Reference::query()->findOrFail(ReferenceFormSchema::createPending($data));

    expect($preview)->toBe('Riyadhus Solihin — Edisi Semakan — '.$expectedLabel)
        ->and($reference->displayTitle())->toBe($preview);
})->with([
    ['ms', 'jilid', null, 'Jilid 2'],
    ['ms', 'bahagian', null, 'Bahagian 2'],
    ['en', 'jilid', null, 'Volume 2'],
    ['en', 'bahagian', null, 'Part 2'],
    ['ms', 'bahagian', 'Bab Taharah', 'Bab Taharah'],
]);

it('offers the full language catalog as initial options in the reference form', function (): void {
    $this->seed(LanguageSeeder::class);
    app(SelectionCatalogCache::class)->bustLanguages();

    foreach (ReferenceFormSchema::fields() as $candidate) {
        if (! $candidate instanceof Select || $candidate->getName() !== 'language') {
            continue;
        }

        $options = $candidate->getOptions();

        expect(count($options))->toBeGreaterThan(150);
        expect($options)->toHaveKeys(['ms', 'en', 'ar', 'ja', 'fr']);
        expect($options['ar'])->toBe('Arabic (العربية)');
        expect($options['ja'])->toBe('Japanese (日本語)');
        expect($candidate->isSearchable())->toBeTrue();
        expect($candidate->isPreloaded())->toBeTrue();

        return;
    }

    $this->fail('Expected a language select in the reference form.');
});
