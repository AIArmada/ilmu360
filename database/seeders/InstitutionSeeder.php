<?php

namespace Database\Seeders;

use App\Enums\ContactCategory;
use App\Enums\ContactType;
use App\Models\Country;
use App\Models\Institution;
use App\Models\State;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class InstitutionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $realInstitutions = [
            [
                'name' => 'Masjid Wilayah Persekutuan',
                'type' => 'masjid',
                'line1' => 'Jalan Duta',
                'city' => 'Kuala Lumpur',
                'state_name' => 'Kuala Lumpur',
                'lat' => 3.1614755,
                'lng' => 101.6701549,
            ],
            [
                'name' => 'Masjid Tuanku Mizan Zainal Abidin (Masjid Besi)',
                'type' => 'masjid',
                'line1' => 'Presint 3',
                'city' => 'Putrajaya',
                'state_name' => 'Putrajaya',
                'lat' => 2.9221376,
                'lng' => 101.6841203,
            ],
            [
                'name' => 'Pusat Islam Petaling Jaya',
                'type' => 'masjid',
                'line1' => 'Jalan Gasing',
                'city' => 'Petaling Jaya',
                'state_name' => 'Selangor',
                'lat' => 3.1026,
                'lng' => 101.6521,
            ],
            [
                'name' => 'Surau Ar-Raudhah',
                'type' => 'surau',
                'line1' => 'Seksyen 7',
                'city' => 'Shah Alam',
                'state_name' => 'Selangor',
                'lat' => 3.0746,
                'lng' => 101.4883,
            ],
        ];

        $countries = Country::query()->get();
        $malaysia = $countries->where('iso2', 'MY')->first() ?? $countries->first();
        $states = State::query()->where('country_id', $malaysia->id)->with(['districts', 'cities'])->get();

        $this->command->info('Seeding featured institutions with coordinates...');

        // 1. Seed Real Institutions with coordinates (skip mosques that are already in CSV)
        foreach ($realInstitutions as $data) {
            $stateMatch = $states->filter(fn ($s) => Str::contains(strtolower((string) $s->name), strtolower($data['state_name'])))->first();

            // Fallback to random if not found, or skip? better to random.
            $state = $stateMatch ?? $states->random();
            $district = collect($state->districts)->isNotEmpty() ? collect($state->districts)->random() : null;
            $city = collect($state->cities)->isNotEmpty() ? collect($state->cities)->random() : null;

            $inst = Institution::firstOrCreate(
                ['name' => $data['name']],
                [
                    'slug' => Str::slug($data['name']),
                    'type' => $data['type'],
                    'status' => 'verified',
                    'is_active' => true,
                ]
            );

            // Create contacts
            $inst->contacts()->firstOrCreate(
                ['category' => ContactCategory::Email->value],
                ['value' => Str::slug($data['name']).'@example.com', 'type' => ContactType::Work->value]
            );

            $inst->contacts()->firstOrCreate(
                ['category' => ContactCategory::Phone->value],
                ['value' => '03-'.fake()->numberBetween(1000000, 9999999), 'type' => ContactType::Work->value]
            );

            // Create or update address
            $inst->address()->updateOrCreate([], [
                'line1' => $data['line1'],
                'postcode' => fake()->postcode(),
                'country_id' => $malaysia?->getKey(),
                'state_id' => $state->getKey(),
                'district_id' => $district?->getKey(),
                'city_id' => $city?->getKey(),
                'lat' => $data['lat'],
                'lng' => $data['lng'],
            ]);

            // Skip authorization for speed
            // $inst->ensureAuthzScope();
            // $this->seedInstitutionRoles($inst);

            // Attach random owner
            // if ($users->isNotEmpty()) {
            //     $owner = $users->random();
            //     $inst->members()->syncWithoutDetaching([$owner->id]);
            //     $this->syncMemberRoles($inst, $owner, ['owner']);
            // }
        }

        $this->command->info('Completed seeding featured institutions.');

        // 2. Seed Additional Fake Institutions (surau, educational centers, etc.)
        // Add variety to complement the real mosque data
        $additionalTypes = [
            'surau' => 30,
            'madrasah' => 15,
            'masjid' => 10,
        ];

        $this->command->info('Seeding additional institutions...');

        foreach ($additionalTypes as $type => $count) {
            $institutions = Institution::factory()->count($count)->create([
                'type' => $type,
                'status' => 'verified',
            ]);

            $institutions->each(function (Institution $institution) use ($malaysia, $states): void {
                if ($states->isNotEmpty()) {
                    $state = $states->random();
                    $district = collect($state->districts)->isNotEmpty() ? collect($state->districts)->random() : null;
                    $city = collect($state->cities)->isNotEmpty() ? collect($state->cities)->random() : null;

                    $institution->address()->update([
                        'country_id' => $malaysia?->getKey(),
                        'state_id' => $state->getKey(),
                        'district_id' => $district?->getKey(),
                        'city_id' => $city?->getKey(),
                    ]);
                }

                // Skip authorization setup for speed - will be set up on first access
                // $institution->ensureAuthzScope();
                // $this->seedInstitutionRoles($institution);

                // if ($users->isNotEmpty()) {
                //     $owner = $users->random();
                //     $institution->members()->syncWithoutDetaching([$owner->id]);
                //     $this->syncMemberRoles($institution, $owner, ['owner']);
                // }
            });
        }

        $this->command->info('Completed seeding additional institutions.');
    }
}
