<?php

use App\Models\Person;
use App\Models\Reference;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Support\Facades\DB;

it('seeds embedded production references with authors as person links', function () {
    $this->seed(ReferenceSeeder::class);

    expect(Reference::query()->count())->toBe(83);

    $types = Reference::query()->pluck('type')->unique()->values()->all();
    $statuses = Reference::query()->pluck('status')->unique()->values()->all();

    expect($types)->toBe(['book'])
        ->and($statuses)->toBe(['verified']);

    $reference = Reference::query()->where('slug', 'al-fiqh-al-manhaji')->first();

    expect($reference)->not->toBeNull()
        ->and((string) $reference?->status)->toBe('verified')
        ->and($reference?->year)->toBeNull()
        ->and($reference?->published_at)->not->toBeNull()
        ->and($reference?->slug)->toBe('al-fiqh-al-manhaji')
        ->and($reference?->authors()->count())->toBe(3);

    $author = Person::query()->where('name', 'Dr Mustafa Al-Bugha')->first();

    expect($author)->not->toBeNull()
        ->and($author?->speaker_status->value)->toBe('inactive')
        ->and($author?->allow_public_event_submission)->toBeFalse();

    $part = Reference::query()->where('slug', 'sunting-idaman-penuntut-pada-menghuraikan-yang-kusut-ilmu-fiqah')->first();

    expect($part)->not->toBeNull()
        ->and($part?->recordKindValue())->toBe('part')
        ->and((string) $part?->parentIdValue())->toBe('019d477a-623c-7225-95db-a665a368fc61')
        ->and($part?->authors()->count())->toBe(0);
});

it('seeds embedded reference cover media pointing at S3', function () {
    $this->seed(ReferenceSeeder::class);

    $covers = DB::table('media')->where('collection_name', 'front_cover');

    expect($covers->count())->toBe(48)
        ->and($covers->where('disk', '!=', 's3')->count())->toBe(0);

    expect(DB::table('media')->where('collection_name', 'back_cover')->count())->toBe(1)
        ->and(DB::table('media')->where('collection_name', 'gallery')->count())->toBe(2);

    $sample = DB::table('media')
        ->where('collection_name', 'front_cover')
        ->where('model_type', (new Reference)->getMorphClass())
        ->first();

    expect($sample)->not->toBeNull();

    expect(Reference::query()->whereKey($sample->model_id)->exists())->toBeTrue();
});

it('keeps embedded production references idempotent on reseeding', function () {
    $this->seed(ReferenceSeeder::class);

    $counts = [Reference::query()->count(), Person::query()->count()];

    $this->seed(ReferenceSeeder::class);

    expect([Reference::query()->count(), Person::query()->count()])->toBe($counts);
});
