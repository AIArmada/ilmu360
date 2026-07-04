<?php

namespace Database\Seeders;

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use App\Models\Institution;
use Database\Seeders\Concerns\SeedsPackageAddresses;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class InstitutionSeeder extends Seeder
{
    use SeedsPackageAddresses;

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

        $malaysia = $this->malaysiaCountry();
        $states = AddressArea::query()
            ->where('country_code', 'MY')
            ->where('level', 1)
            ->orderBy('name')
            ->get();

        $this->command->info('Seeding featured institutions with coordinates...');

        // 1. Seed Real Institutions with coordinates (skip mosques that are already in CSV)
        foreach ($realInstitutions as $data) {
            $stateMatch = $states->filter(fn ($s) => Str::contains(strtolower((string) $s->name), strtolower($data['state_name'])))->first();

            $state = $stateMatch ?? ($states->isNotEmpty() ? $states->random() : null);
            $district = $state instanceof AddressArea
                ? AddressArea::query()->where('parent_id', $state->id)->inRandomOrder()->first()
                : null;

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
                ['type' => ContactMethodType::Email->value],
                ['value' => Str::slug($data['name']).'@example.com', 'purpose' => ContactPurpose::General->value]
            );

            $inst->contacts()->firstOrCreate(
                ['type' => ContactMethodType::Phone->value],
                ['value' => '03-'.fake()->numberBetween(1000000, 9999999), 'purpose' => ContactPurpose::General->value]
            );

            // Create or update address
            $this->seedPrimaryPackageAddress($inst, [
                'line1' => $data['line1'],
                'postcode' => fake()->postcode(),
                'country_id' => $malaysia?->id,
                'admin_area_1_id' => $state instanceof AddressArea ? $state->id : null,
                'admin_area_2_id' => $district instanceof AddressArea ? $district->id : null,
                'latitude' => $data['lat'],
                'longitude' => $data['lng'],
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
                    $district = AddressArea::query()->where('parent_id', $state->id)->inRandomOrder()->first();

                    $this->seedPrimaryPackageAddress($institution, [
                        'line1' => $institution->addressModel?->line1,
                        'line2' => $institution->addressModel?->line2,
                        'postcode' => $institution->addressModel?->postcode,
                        'country_id' => $malaysia?->id,
                        'admin_area_1_id' => $state->id,
                        'admin_area_2_id' => $district instanceof AddressArea ? $district->id : null,
                        'latitude' => $institution->addressModel?->latitude,
                        'longitude' => $institution->addressModel?->longitude,
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
