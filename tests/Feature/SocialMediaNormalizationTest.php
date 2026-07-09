<?php

use AIArmada\Contacting\Enums\SocialPlatform;
use App\Models\Institution;
use App\Models\Speaker;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('extracts instagram username from full profile url and resolves canonical url', function () {
    $speaker = Speaker::factory()->create();

    $social = $speaker->socialProfiles()->create([
        'platform' => SocialPlatform::Instagram->value,
        'url' => 'https://www.instagram.com/ustazah.aminah/?hl=en',
    ])->fresh();

    expect($social->handle)->toBe('ustazah.aminah')
        ->and($social->url)->toBe('https://www.instagram.com/ustazah.aminah/?hl=en')
        ->and($social->profileUrl())->toBe('https://www.instagram.com/ustazah.aminah');
});

it('accepts @handle input and resolves a tiktok url', function () {
    $speaker = Speaker::factory()->create();

    $social = $speaker->socialProfiles()->create([
        'platform' => SocialPlatform::Tiktok->value,
        'handle' => '@ilmu360',
    ])->fresh();

    expect($social->handle)->toBe('ilmu360')
        ->and($social->url)->toBe('https://www.tiktok.com/@ilmu360')
        ->and($social->profileUrl())->toBe('https://www.tiktok.com/@ilmu360');
});

it('normalizes x links and preserves the x platform key', function () {
    $institution = Institution::factory()->create();

    $social = $institution->socialProfiles()->create([
        'platform' => SocialPlatform::X->value,
        'url' => 'https://x.com/ilmu360',
    ])->fresh();

    expect($social->platform)->toBe(SocialPlatform::X->value)
        ->and($social->handle)->toBe('ilmu360')
        ->and($social->url)->toBe('https://x.com/ilmu360')
        ->and($social->profileUrl())->toBe('https://x.com/ilmu360');
});

it('builds canonical facebook links from handles', function () {
    $speaker = Speaker::factory()->create();

    $social = $speaker->socialProfiles()->create([
        'platform' => SocialPlatform::Facebook->value,
        'handle' => 'nurul',
    ])->fresh();

    expect($social->platform)->toBe(SocialPlatform::Facebook->value)
        ->and($social->handle)->toBe('nurul')
        ->and($social->url)->toBe('https://www.facebook.com/nurul')
        ->and($social->profileUrl())->toBe('https://www.facebook.com/nurul');
});

it('normalizes website urls when given as direct links', function () {
    $institution = Institution::factory()->create();

    $social = $institution->socialProfiles()->create([
        'platform' => SocialPlatform::Website->value,
        'url' => 'ilmu360.test/profile',
    ])->fresh();

    expect($social->handle)->toBeNull()
        ->and($social->url)->toBe('https://ilmu360.test/profile')
        ->and($social->profileUrl())->toBe('https://ilmu360.test/profile');
});

it('keeps custom social links under the other platform', function () {
    $institution = Institution::factory()->create();

    $social = $institution->socialProfiles()->create([
        'platform' => SocialPlatform::Other->value,
        'url' => 'https://en.wikipedia.org/wiki/Imam_al-Nawawi',
    ])->fresh();

    expect($social->platform)->toBe(SocialPlatform::Other->value)
        ->and($social->handle)->toBeNull()
        ->and($social->url)->toBe('https://en.wikipedia.org/wiki/Imam_al-Nawawi')
        ->and($social->profileUrl())->toBe('https://en.wikipedia.org/wiki/Imam_al-Nawawi');
});

it('renders resolved social url on speaker page when url column is null', function () {
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
    ]);

    $speaker->socialProfiles()->create([
        'platform' => SocialPlatform::Instagram->value,
        'handle' => 'ustazah.aminah',
    ]);

    $this->get(route('speakers.show', $speaker))
        ->assertSuccessful()
        ->assertSee('https://www.instagram.com/ustazah.aminah', false);
});
