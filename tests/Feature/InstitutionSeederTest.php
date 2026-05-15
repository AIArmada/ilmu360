<?php

use App\Models\User;
use Database\Seeders\AdvancedEventSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DistrictSeeder;
use Database\Seeders\DonationChannelSeeder;
use Database\Seeders\EventSeeder;
use Database\Seeders\EventSubmissionSeeder;
use Database\Seeders\InspirationSeeder;
use Database\Seeders\InstitutionSeeder;
use Database\Seeders\MalaysiaCitySeeder;
use Database\Seeders\MasjidSeeder;
use Database\Seeders\MediaLinkSeeder;
use Database\Seeders\ModerationReviewSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RegistrationSeeder;
use Database\Seeders\ReportSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SavedSearchSeeder;
use Database\Seeders\ScopedMemberRolesSeeder;
use Database\Seeders\SeriesSeeder;
use Database\Seeders\SpaceSeeder;
use Database\Seeders\SpeakerSeeder;
use Database\Seeders\SubdistrictSeeder;
use Database\Seeders\TagSeeder;
use Database\Seeders\UserSeeder;
use Database\Seeders\VenueSeeder;
use Database\Seeders\WorldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('runs the full demo seeding pipeline in the expected order', function () {
    putenv('SEED_MASJID_DIRECTORY=false');

    $calledSeederBatches = [];

    $seeder = Mockery::mock(DatabaseSeeder::class)->makePartial();

    $seeder->shouldReceive('call')
        ->andReturnUsing(function (array|string $seeders) use (&$calledSeederBatches): void {
            $calledSeederBatches[] = is_array($seeders) ? $seeders : [$seeders];
        });

    $seeder->run();

    expect($calledSeederBatches)->toContain([
        WorldSeeder::class,
        MalaysiaCitySeeder::class,
        DistrictSeeder::class,
        SubdistrictSeeder::class,
    ]);

    expect($calledSeederBatches)->toContain([
        PermissionSeeder::class,
        RoleSeeder::class,
        ScopedMemberRolesSeeder::class,
        TagSeeder::class,
        UserSeeder::class,
    ]);

    expect($calledSeederBatches)->toContain([SpaceSeeder::class]);
    expect($calledSeederBatches)->toContain([InstitutionSeeder::class]);
    expect($calledSeederBatches)->toContain([VenueSeeder::class]);
    expect($calledSeederBatches)->toContain([SpeakerSeeder::class]);

    expect($calledSeederBatches)->toContain([
        SeriesSeeder::class,
        EventSeeder::class,
        AdvancedEventSeeder::class,
        ReferenceSeeder::class,
        InspirationSeeder::class,
        DonationChannelSeeder::class,
        MediaLinkSeeder::class,
    ]);

    expect($calledSeederBatches)->toContain([
        EventSubmissionSeeder::class,
        ModerationReviewSeeder::class,
        ReportSeeder::class,
        SavedSearchSeeder::class,
        RegistrationSeeder::class,
    ]);

    expect($calledSeederBatches)->not()->toContain([MasjidSeeder::class]);
});

it('optionally includes the masjid directory seeder when enabled', function () {
    putenv('SEED_MASJID_DIRECTORY=1');

    $calledSeederBatches = [];

    $seeder = Mockery::mock(DatabaseSeeder::class)->makePartial();

    $seeder->shouldReceive('call')
        ->andReturnUsing(function (array|string $seeders) use (&$calledSeederBatches): void {
            $calledSeederBatches[] = is_array($seeders) ? $seeders : [$seeders];
        });

    $seeder->run();

    expect($calledSeederBatches)->toContain([MasjidSeeder::class]);
});

it('tops up demo users without duplicating on subsequent runs', function () {
    putenv('SEED_MASJID_DIRECTORY=false');

    $seeder = Mockery::mock(DatabaseSeeder::class)->makePartial();
    $seeder->shouldReceive('call')->andReturnNull();

    $seeder->run();
    expect(User::query()->count())->toBe(60);

    $seeder->run();
    expect(User::query()->count())->toBe(60);
});
