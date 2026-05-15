<?php

use AIArmada\Signals\Models\TrackedProperty;
use App\Support\Cache\PublicListingsCache;
use App\Support\Signals\ProductSignalsSurfaceResolver;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        PreventRequestForgery::except('*');

        $compiledViewPath = storage_path('framework/views/testing_'.ParallelTesting::token());

        File::ensureDirectoryExists($compiledViewPath);
        config()->set('view.compiled', $compiledViewPath);

        if (app()->resolved('blade.compiler')) {
            $compiler = app('blade.compiler');

            (function () use ($compiledViewPath): void {
                $this->cachePath = $compiledViewPath;
            })->bindTo($compiler, $compiler)();
        }

        if (! Schema::hasTable(config('affiliates.database.tables.affiliates', 'affiliate_affiliates'))) {
            Artisan::call('migrate', [
                '--path' => realpath(base_path('vendor/aiarmada/affiliates/database/migrations')),
                '--realpath' => true,
            ]);
        }

        if (! Schema::hasTable(config('signals.database.tables.tracked_properties', 'signal_tracked_properties'))) {
            Artisan::call('migrate', [
                '--path' => realpath(base_path('vendor/aiarmada/signals/database/migrations')),
                '--realpath' => true,
            ]);
        }

        if (Schema::hasTable(config('signals.database.tables.tracked_properties', 'signal_tracked_properties'))) {
            $surfaceResolver = app(ProductSignalsSurfaceResolver::class);

            foreach (['public' => 'Website', 'admin' => 'Admin'] as $surface => $label) {
                $slug = $surfaceResolver->slugForSurface($surface);

                if (! is_string($slug) || $slug === '') {
                    continue;
                }

                TrackedProperty::query()->firstOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => config('app.name').' '.$label,
                        'write_key' => Str::random(40),
                        'domain' => $surfaceResolver->domainForSurface($surface),
                        'type' => (string) config('signals.defaults.property_type', 'website'),
                        'timezone' => (string) config('signals.defaults.timezone', config('app.timezone', 'UTC')),
                        'currency' => (string) config('signals.defaults.currency', 'MYR'),
                        'is_active' => true,
                    ],
                );
            }
        }

        config()->set('services.turnstile.enabled', false);
        config()->set('services.turnstile.site_key');
        config()->set('services.turnstile.secret_key');

        // Clear tag option caches to prevent stale data in tests
        foreach (['domain', 'discipline', 'source', 'issue'] as $type) {
            Cache::forget("submit_tags_{$type}_ms_safe_v1");
            Cache::forget("submit_tags_{$type}_en_safe_v1");
        }

        foreach (['discipline', 'issue'] as $type) {
            Cache::forget("submit_tags_{$type}_verified_ms_safe_v1");
            Cache::forget("submit_tags_{$type}_verified_en_safe_v1");
        }

        Cache::forget('submit_languages_safe_v1');
        Cache::forget('submit_venues_safe_v1');

        app(PublicListingsCache::class)->bustMajlisListing();

        // Seed common languages for tests that use the submit event form
        $languages = [
            ['id' => 7, 'code' => 'ar', 'name' => 'Arabic', 'name_native' => 'العربية', 'dir' => 'rtl'],
            ['id' => 30, 'code' => 'zh', 'name' => 'Chinese', 'name_native' => '中文', 'dir' => 'ltr'],
            ['id' => 40, 'code' => 'en', 'name' => 'English', 'name_native' => 'English', 'dir' => 'ltr'],
            ['id' => 64, 'code' => 'id', 'name' => 'Indonesian', 'name_native' => 'Bahasa Indonesia', 'dir' => 'ltr'],
            ['id' => 74, 'code' => 'jv', 'name' => 'Javanese', 'name_native' => 'ꦧꦱꦗꦮ', 'dir' => 'ltr'],
            ['id' => 101, 'code' => 'ms', 'name' => 'Malay', 'name_native' => 'bahasa Melayu', 'dir' => 'ltr'],
            ['id' => 154, 'code' => 'ta', 'name' => 'Tamil', 'name_native' => 'தமிழ்', 'dir' => 'ltr'],
        ];

        DB::table('languages')->insertOrIgnore($languages);
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function fakeGeneratedImageUpload(string $name = 'image.png', int $width = 1200, int $height = 800): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);

    if ($image === false) {
        throw new RuntimeException('Unable to create image fixture.');
    }

    $background = imagecolorallocate($image, 24, 90, 160);
    $overlay = imagecolorallocate($image, 245, 248, 250);

    imagefill($image, 0, 0, $background);
    imagefilledrectangle(
        $image,
        (int) ($width * 0.12),
        (int) ($height * 0.12),
        (int) ($width * 0.88),
        (int) ($height * 0.88),
        $overlay,
    );

    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'png';
    $temporaryPath = tempnam(sys_get_temp_dir(), 'ilmu360-test-image-');

    if ($temporaryPath === false) {
        imagedestroy($image);

        throw new RuntimeException('Unable to allocate temporary image fixture path.');
    }

    $imagePath = $temporaryPath.'.'.$extension;

    if (! @rename($temporaryPath, $imagePath)) {
        @unlink($temporaryPath);
        imagedestroy($image);

        throw new RuntimeException('Unable to prepare temporary image fixture path.');
    }

    $encoded = match ($extension) {
        'jpg', 'jpeg' => imagejpeg($image, $imagePath, 90),
        'gif' => imagegif($image, $imagePath),
        'webp' => function_exists('imagewebp') && imagewebp($image, $imagePath, 90),
        default => imagepng($image, $imagePath),
    };

    imagedestroy($image);

    if (! $encoded || ! is_file($imagePath) || filesize($imagePath) === 0) {
        @unlink($imagePath);

        throw new RuntimeException('Unable to encode image fixture.');
    }

    $mimeType = match ($extension) {
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        default => 'image/png',
    };

    return new UploadedFile($imagePath, $name, $mimeType, null, true);
}

function fakePrayerTimesApi(): void
{
    // Submit-event feature tests do not exercise real notification delivery.
    Notification::fake();

    Http::fake([
        'api.aladhan.com/*' => Http::response([
            'code' => 200,
            'status' => 'OK',
            'data' => [
                'timings' => [
                    'Fajr' => '05:50',
                    'Dhuhr' => '13:15',
                    'Asr' => '16:40',
                    'Maghrib' => '19:25',
                    'Isha' => '20:35',
                ],
            ],
        ], 200),
    ]);
}

/**
 * @param  array<string, mixed>  $state
 */
function setSubmitEventFormState(mixed $component, array $state): mixed
{
    $nonUploadState = [];

    foreach ($state as $field => $value) {
        if (
            $value instanceof UploadedFile ||
            (is_array($value) && isset($value[0]) && $value[0] instanceof UploadedFile)
        ) {
            $component->set("data.{$field}", $value);

            continue;
        }

        $nonUploadState[$field] = $value;
    }

    if ($nonUploadState !== []) {
        if (method_exists($component, 'fillForm')) {
            $component->fillForm($nonUploadState);
        } else {
            foreach ($nonUploadState as $field => $value) {
                $component->set("data.{$field}", $value);
            }
        }
    }

    return $component;
}
