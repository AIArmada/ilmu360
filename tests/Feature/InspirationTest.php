<?php

use App\Enums\InspirationCategory;
use App\Filament\Resources\Inspirations\InspirationResource;
use App\Models\Event;
use App\Models\Inspiration;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\User;
use Database\Seeders\InspirationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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
        ->and($inspiration->is_active)->toBeTrue();
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

it('shows sidebar inspiration on speaker page', function () {
    Inspiration::factory()->category(InspirationCategory::QuranQuote)->create([
        'title' => 'Test Quran Quote',
        'content' => 'Test content for speaker page',
    ]);

    $speaker = Speaker::factory()->create(['status' => 'verified']);

    $this->get(route('speakers.show', $speaker))
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
    $speaker = Speaker::factory()->create(['status' => 'verified']);

    $this->get(route('speakers.show', $speaker))
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

    $speaker = Speaker::factory()->create(['status' => 'verified']);

    $this->get(route('speakers.show', $speaker))
        ->assertSuccessful()
        ->assertDontSee('Inactive Inspiration Hidden');
});

it('does not show inspiration from a different locale', function () {
    Inspiration::factory()->locale('en')->create([
        'title' => 'English Only Inspiration',
        'content' => 'This should not appear in Malay',
    ]);

    app()->setLocale('ms');
    $speaker = Speaker::factory()->create(['status' => 'verified']);

    $this->get(route('speakers.show', $speaker))
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
