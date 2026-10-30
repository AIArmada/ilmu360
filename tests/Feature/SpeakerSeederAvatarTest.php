<?php

use App\Models\Person;
use Database\Seeders\SpeakerSeeder;
use Illuminate\Support\Facades\DB;

it('seeds speaker avatar media metadata pointing at S3', function () {
    $this->seed(SpeakerSeeder::class);

    $avatars = DB::table('media')->where('collection_name', 'avatar');

    expect($avatars->count())->toBe(100)
        ->and($avatars->where('disk', '!=', 's3')->count())->toBe(0);

    $sample = DB::table('media')
        ->where('collection_name', 'avatar')
        ->where('model_type', (new Person)->getMorphClass())
        ->first();

    expect($sample)->not->toBeNull()
        ->and((array) json_decode((string) $sample->generated_conversions, true))->toMatchArray(['thumb' => true, 'profile' => true]);

    expect(Person::query()->whereKey($sample->model_id)->exists())->toBeTrue();
});

it('keeps speaker avatar media idempotent on reseeding', function () {
    $this->seed(SpeakerSeeder::class);

    $count = DB::table('media')->where('collection_name', 'avatar')->count();

    $this->seed(SpeakerSeeder::class);

    expect(DB::table('media')->where('collection_name', 'avatar')->count())->toBe($count);
});
