<?php

namespace Database\Seeders;

use App\Models\Institution;
use App\Models\Speaker;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    private const int DEMO_USER_TARGET = 60;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->call([ProductionSeeder::class]);

            return;
        }

        $this->seedGeography();
        $this->seedAuthAndTaxonomy();
        $this->seedPrimaryEntities();
        $this->seedActivityAndModeration();
    }

    private function seedGeography(): void
    {
        $this->call([
            WorldSeeder::class,
            MalaysiaCitySeeder::class,
            DistrictSeeder::class,
            SubdistrictSeeder::class,
        ]);
    }

    private function seedAuthAndTaxonomy(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            ScopedMemberRolesSeeder::class,
            TagSeeder::class,
            UserSeeder::class,
        ]);

        $this->topUpDemoUsers();
    }

    private function seedPrimaryEntities(): void
    {
        // Shared entities used by institution/event forms.
        $this->call([SpaceSeeder::class]);

        // Guard non-idempotent seeders.
        $this->seedWhenEmpty(Institution::class, InstitutionSeeder::class);
        $this->seedWhenEmpty(Venue::class, VenueSeeder::class);
        $this->seedWhenEmpty(Speaker::class, SpeakerSeeder::class);

        // Optional national masjid directory import.
        if ($this->shouldSeedMasjidDirectory()) {
            $this->call([MasjidSeeder::class]);
        }

        $this->call([
            SeriesSeeder::class,
            EventSeeder::class,
            AdvancedEventSeeder::class,
            ReferenceSeeder::class,
            InspirationSeeder::class,
            DonationChannelSeeder::class,
            MediaLinkSeeder::class,
        ]);
    }

    private function seedActivityAndModeration(): void
    {
        // Dependent records that illustrate moderation + engagement workflows.
        $this->call([
            EventSubmissionSeeder::class,
            ModerationReviewSeeder::class,
            ReportSeeder::class,
            SavedSearchSeeder::class,
            RegistrationSeeder::class,
        ]);
    }

    private function topUpDemoUsers(): void
    {
        $existingUsers = User::query()->count();
        $usersToCreate = max(0, self::DEMO_USER_TARGET - $existingUsers);

        if ($usersToCreate === 0) {
            return;
        }

        User::factory()->count($usersToCreate)->create();
    }

    private function shouldSeedMasjidDirectory(): bool
    {
        $seedMasjidDirectory = getenv('SEED_MASJID_DIRECTORY');

        return filter_var($seedMasjidDirectory === false ? 'false' : $seedMasjidDirectory, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  class-string<Seeder>  $seederClass
     */
    private function seedWhenEmpty(string $modelClass, string $seederClass): void
    {
        if ($modelClass::query()->exists()) {
            return;
        }

        $this->call([$seederClass]);
    }
}
