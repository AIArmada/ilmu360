<?php

use App\Features\Inspirations\Actions\SaveInspirationAction;
use App\Features\Inspirations\EntryPoints\Filament\Resources\Inspirations\InspirationResource;
use App\Features\Inspirations\EntryPoints\Filament\Resources\Inspirations\Pages\CreateInspiration;
use App\Features\Inspirations\EntryPoints\Filament\Resources\Inspirations\Pages\EditInspiration;
use App\Features\Inspirations\EntryPoints\Filament\Resources\Inspirations\Pages\ListInspirations;
use App\Features\Inspirations\Enums\InspirationCategory;
use App\Features\Inspirations\Models\Inspiration;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\InspirationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');
});

it('creates inspirations via factory with correct attributes', function () {
    $inspiration = Inspiration::factory()->create();

    expect($inspiration->id)->not->toBeNull()
        ->and($inspiration->category)->toBeInstanceOf(InspirationCategory::class)
        ->and($inspiration->locale)->toBe('ms')
        ->and($inspiration->title)->toBeString()
        ->and($inspiration->content)->toBeArray()
        ->and($inspiration->content['type'] ?? null)->toBe('doc')
        ->and((string) $inspiration->status)->toBeIn(['active', 'inactive']);
});

it('normalizes plain text content into rich json structure', function () {
    $inspiration = Inspiration::factory()->create([
        'content' => 'Plain text content for normalization',
    ]);

    expect($inspiration->content)->toBeArray()
        ->and($inspiration->content['type'] ?? null)->toBe('doc')
        ->and($inspiration->content['content'][0]['content'][0]['text'] ?? null)
        ->toBe('Plain text content for normalization')
        ->and($inspiration->renderContentHtml())->toContain('Plain text content for normalization');
});

it('scopes active inspirations', function () {
    Inspiration::factory()->count(3)->create();
    Inspiration::factory()->inactive()->count(2)->create();

    expect(Inspiration::query()->active()->count())->toBe(3);
});

it('scopes inspirations by locale', function () {
    Inspiration::factory()->locale('ms')->count(3)->create();
    Inspiration::factory()->locale('en')->count(2)->create();
    Inspiration::factory()->locale('zh')->create();

    expect(Inspiration::query()->forLocale('ms')->count())->toBe(3)
        ->and(Inspiration::query()->forLocale('en')->count())->toBe(2)
        ->and(Inspiration::query()->forLocale('zh')->count())->toBe(1);
});

it('filters by current app locale by default', function () {
    Inspiration::factory()->locale('ms')->count(2)->create();
    Inspiration::factory()->locale('en')->count(3)->create();

    app()->setLocale('ms');
    expect(Inspiration::query()->active()->forLocale()->count())->toBe(2);

    app()->setLocale('en');
    expect(Inspiration::query()->active()->forLocale()->count())->toBe(3);
});

it('supports image media collection for inspiration', function () {
    $inspiration = Inspiration::factory()->create();

    $inspiration->addMedia(UploadedFile::fake()->image('inspiration.jpg', 1200, 900))
        ->toMediaCollection('main');

    $media = $inspiration->getFirstMedia('main');

    expect($media)->not->toBeNull()
        ->and($inspiration->hasMedia('main'))->toBeTrue()
        ->and($media?->getMediaConversionNames())->toContain('thumb');
});

it('supports all inspiration categories', function () {
    foreach (InspirationCategory::cases() as $category) {
        $inspiration = Inspiration::factory()->category($category)->create();

        expect($inspiration->category)->toBe($category)
            ->and($category->label())->toBeString()
            ->and($category->icon())->toBeString()
            ->and($category->color())->toBeString();
    }
});

it('seeds inspirations via InspirationSeeder', function () {
    $this->seed(InspirationSeeder::class);

    expect(Inspiration::query()->count())->toBeGreaterThanOrEqual(18);

    foreach (InspirationCategory::cases() as $category) {
        expect(Inspiration::query()->where('category', $category)->count())->toBeGreaterThanOrEqual(1);
    }
});

it('shows sidebar inspiration on person page', function () {
    Inspiration::factory()->category(InspirationCategory::QuranQuote)->create([
        'title' => 'Test Quran Quote',
        'content' => 'Test content for person page',
    ]);

    $person = Person::factory()->create(['status' => 'verified']);

    $this->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertSee('Test Quran Quote');
});

it('shows sidebar inspiration image instead of text when media exists', function () {
    $inspiration = Inspiration::factory()->category(InspirationCategory::QuranQuote)->create([
        'title' => 'Image Inspiration',
        'content' => 'This text should be replaced by image preview',
    ]);

    $inspiration->addMedia(UploadedFile::fake()->image('image-inspiration.jpg', 1200, 900))
        ->toMediaCollection('main');

    $mediaUrl = $inspiration->getFirstMedia('main')?->getAvailableUrl(['thumb']);
    $person = Person::factory()->create(['status' => 'verified']);

    $this->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertSee('Image Inspiration')
        ->assertSee((string) $mediaUrl, false)
        ->assertDontSee('This text should be replaced by image preview');
});

it('shows sidebar inspiration on institution page', function () {
    Inspiration::factory()->category(InspirationCategory::HadithQuote)->create([
        'title' => 'Test Hadith Quote',
        'content' => 'Test content for institution page',
    ]);

    $institution = Institution::factory()->create(['status' => 'verified']);

    $this->get(route('institutions.show', $institution))
        ->assertSuccessful()
        ->assertSee('Test Hadith Quote');
});

it('does not render sidebar inspiration on event page', function () {
    Inspiration::factory()->category(InspirationCategory::DidYouKnow)->create([
        'title' => 'Test Did You Know',
        'content' => 'Test content for event page',
    ]);

    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDay(),
    ]);

    $this->get(route('events.show', $event))
        ->assertSuccessful()
        ->assertDontSee('Test Did You Know');
});

it('does not show inactive inspiration on public pages', function () {
    Inspiration::factory()->inactive()->create([
        'title' => 'Inactive Inspiration Hidden',
        'content' => 'This should not be visible',
    ]);

    $person = Person::factory()->create(['status' => 'verified']);

    $this->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertDontSee('Inactive Inspiration Hidden');
});

it('does not show inspiration from a different locale', function () {
    Inspiration::factory()->locale('en')->create([
        'title' => 'English Only Inspiration',
        'content' => 'This should not appear in Malay',
    ]);

    app()->setLocale('ms');
    $person = Person::factory()->create(['status' => 'verified']);

    $this->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertDontSee('English Only Inspiration');
});

it('allows super admin to access inspiration admin index', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)
        ->get(InspirationResource::getUrl('index'))
        ->assertSuccessful();
});

it('creates inspirations through the Filament resource action with trimmed values and media', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    Livewire::actingAs($admin)
        ->test(CreateInspiration::class)
        ->fillForm([
            'category' => InspirationCategory::QuranQuote->value,
            'locale' => 'ms',
            'title' => '  Trimmed Filament Inspiration  ',
            'content' => ['type' => 'doc', 'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => 'Filament content body.']],
            ]]],
            'source' => '  Filament Source  ',
            'status' => 'active',
        ])
        ->set('data.main', [TemporaryUploadedFile::fake()->image('filament-inspiration.jpg', 1200, 900)])
        ->call('create')
        ->assertHasNoFormErrors();

    $inspiration = Inspiration::query()->where('title', 'Trimmed Filament Inspiration')->firstOrFail();

    expect($inspiration->source)->toBe('Filament Source')
        ->and($inspiration->content['content'][0]['content'][0]['text'] ?? null)->toBe('Filament content body.')
        ->and($inspiration->getMedia('main'))->toHaveCount(1);
});

it('updates inspirations through the Filament resource action and replaces the main media', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $inspiration = Inspiration::factory()->create([
        'title' => 'Original Filament Inspiration',
        'source' => 'Original Source',
    ]);
    $inspiration->addMedia(UploadedFile::fake()->image('original-inspiration.jpg', 1200, 900))
        ->toMediaCollection('main');
    $originalMediaId = $inspiration->getFirstMedia('main')?->getKey();

    Livewire::actingAs($admin)
        ->test(EditInspiration::class, ['record' => $inspiration->getRouteKey()])
        ->fillForm([
            'title' => '  Updated Filament Inspiration  ',
            'source' => '   ',
            'content' => ['type' => 'doc', 'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => 'Updated Filament content.']],
            ]]],
        ])
        ->set('data.main', [TemporaryUploadedFile::fake()->image('replacement-inspiration.jpg', 1200, 900)])
        ->call('save')
        ->assertHasNoFormErrors();

    $inspiration->refresh();

    expect($inspiration->title)->toBe('Updated Filament Inspiration')
        ->and($inspiration->source)->toBeNull()
        ->and($inspiration->content['content'][0]['content'][0]['text'] ?? null)->toBe('Updated Filament content.')
        ->and($inspiration->getMedia('main'))->toHaveCount(1)
        ->and($inspiration->getFirstMedia('main')?->getKey())->not->toBe($originalMediaId);
});

it('deletes a single inspiration from the Filament edit header action', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $selected = Inspiration::factory()->create();
    $survivor = Inspiration::factory()->create();

    Livewire::actingAs($admin)
        ->test(EditInspiration::class, ['record' => $selected->getRouteKey()])
        ->callAction('delete');

    $this->assertModelMissing($selected);
    $this->assertModelExists($survivor);
});

it('toggles every selected inspiration from the Filament table bulk action', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $active = Inspiration::factory()->create(['status' => 'active']);
    $inactive = Inspiration::factory()->inactive()->create();

    Livewire::actingAs($admin)
        ->test(ListInspirations::class)
        ->callTableBulkAction('toggleStatus', [$active, $inactive])
        ->assertHasNoTableBulkActionErrors();

    expect((string) $active->fresh()->status)->toBe('inactive')
        ->and((string) $inactive->fresh()->status)->toBe('active');
});

it('deletes only the selected inspirations from the Filament table bulk action', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $first = Inspiration::factory()->create();
    $second = Inspiration::factory()->create();
    $survivor = Inspiration::factory()->create();

    Livewire::actingAs($admin)
        ->test(ListInspirations::class)
        ->callTableBulkAction('delete', [$first, $second])
        ->assertHasNoTableBulkActionErrors();

    $this->assertModelMissing($first);
    $this->assertModelMissing($second);
    $this->assertModelExists($survivor);
});

it('normalizes and revalidates inspiration input through the save action', function () {
    $action = app(SaveInspirationAction::class);

    $inspiration = $action->handle([
        'category' => InspirationCategory::HadithQuote,
        'locale' => 'ms',
        'title' => '  Direct Save Title  ',
        'content' => 'Direct save content.',
        'source' => '   ',
        'status' => 'active',
    ]);

    expect($inspiration->title)->toBe('Direct Save Title')
        ->and($inspiration->source)->toBeNull();

    expect(fn () => $action->handle([
        'category' => 'invalid_category',
        'locale' => 'ms',
        'title' => 'Invalid category',
        'content' => 'Content.',
    ]))->toThrow(ValidationException::class);

    expect(fn () => $action->handle([
        'category' => InspirationCategory::QuranQuote->value,
        'locale' => 'invalid_locale',
        'title' => 'Invalid locale',
        'content' => 'Content.',
    ]))->toThrow(ValidationException::class);
});

it('denies bulk inspiration deletion to moderators and preserves the records', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $inspiration = Inspiration::factory()->create();

    Livewire::actingAs($moderator)
        ->test(ListInspirations::class)
        ->assertCanSeeTableRecords([$inspiration])
        ->assertTableBulkActionHidden('delete');

    $this->assertModelExists($inspiration);
});
