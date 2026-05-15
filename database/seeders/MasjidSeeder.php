<?php

namespace Database\Seeders;

use App\Enums\ContactCategory;
use App\Enums\ContactType;
use App\Models\Country;
use App\Models\District;
use App\Models\Institution;
use App\Models\State;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MasjidSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $csvPath = database_path('seeders/senarai_masjid.csv');

        if (! File::exists($csvPath)) {
            $this->command->warn('CSV file not found: '.$csvPath);

            return;
        }

        // Create additional users for mosque personnel if needed
        $existingUserCount = User::count();
        if ($existingUserCount < 300) {
            $this->command->info('Creating '.(300 - $existingUserCount).' additional users for mosque personnel...');
            User::factory()->count(300 - $existingUserCount)->create();
        }

        $countries = Country::query()->get();
        $malaysia = $countries->where('iso2', 'MY')->first() ?? $countries->first();
        $states = State::query()->where('country_id', $malaysia->id)->with(['districts'])->get();
        User::query()->get();

        // Read CSV file
        $handle = fopen($csvPath, 'r');
        fgetcsv($handle, escape: '\\'); // Skip header row

        $count = 0;
        $skipped = 0;
        $limit = 300;
        // Process mosques from CSV (limited to 300)

        while ($count < $limit && ($row = fgetcsv($handle, escape: '\\')) !== false) {
            // CSV columns: No., Nama, Alamat, Negeri, Daerah, No. Tel, Fax
            [, $nama, $alamat, $negeri, $daerah, $noTel] = $row;

            // Skip if no name
            if (in_array($nama, [null, '', '0'], true) || trim($nama) === '') {
                continue;
            }

            // Clean the name
            $nama = trim($nama);
            $alamat = trim((string) $alamat);
            $negeri = trim((string) $negeri);
            $daerah = trim((string) $daerah);
            $noTel = trim((string) $noTel);

            // Find matching state
            $state = $this->findState($states, $negeri);
            if (! $state instanceof State) {
                // If we can't find the state, skip this mosque
                $skipped++;

                continue;
            }

            // Find matching district
            $district = null;
            if ($daerah && $state->districts) {
                $district = $state->districts->first(fn (District $district): bool => Str::contains(strtolower((string) $district->name), strtolower($daerah)) ||
                    Str::contains(strtolower($daerah), strtolower((string) $district->name)));
            }

            // Create or update institution
            // Create unique slug by combining name + district/state to handle duplicate names
            $slugBase = Str::slug($nama);
            $locationPart = $daerah ?: $negeri;
            $slug = $slugBase.'-'.Str::slug($locationPart);

            // Ensure uniqueness by appending counter if needed
            $originalSlug = $slug;
            $counter = 1;
            while (Institution::where('slug', $slug)->exists()) {
                $slug = $originalSlug.'-'.$counter;
                $counter++;
            }

            $inst = Institution::create([
                'name' => $nama,
                'slug' => $slug,
                'type' => 'masjid',
                'status' => 'verified',
                'is_active' => true,
            ]);

            // Create contacts
            if ($noTel !== '' && $noTel !== '0') {
                // Clean phone number
                $cleanedPhone = $this->cleanPhoneNumber($noTel);
                if ($cleanedPhone) {
                    try {
                        $inst->contacts()->create([
                            'category' => ContactCategory::Phone->value,
                            'value' => $cleanedPhone,
                            'type' => ContactType::Work->value,
                        ]);
                    } catch (\Exception $e) {
                        $this->command->warn("Failed to create contact for {$nama}: ".$e->getMessage());
                    }
                }
            }

            // Create address
            try {
                $inst->address()->create([
                    'line1' => $alamat ?: null,
                    'postcode' => null,
                    'country_id' => $malaysia?->getKey(),
                    'state_id' => $state->getKey(),
                    'district_id' => $district?->getKey(),
                    'city_id' => null,
                    'lat' => null,
                    'lng' => null,
                ]);
            } catch (\Exception $e) {
                $this->command->warn("Failed to create address for {$nama}: ".$e->getMessage());
            }

            // Skip authorization setup to speed up seeding
            // Authorization will be set up when institution is first accessed
            // $inst->ensureAuthzScope();
            // $this->seedInstitutionRoles($inst);

            // Attach random owner (skip for performance)
            // if ($users->isNotEmpty()) {
            //     $owner = $users->random();
            //     $inst->members()->syncWithoutDetaching([$owner->id]);
            //     $this->syncMemberRoles($inst, $owner, ['owner']);
            // }

            $count++;

            if ($count % 100 === 0) {
                $this->command->info("Seeded {$count} mosques...");
            }
        }

        fclose($handle);

        $this->command->info("Successfully seeded {$count} mosques from CSV (skipped {$skipped} due to missing state matches).");
    }

    /**
     * Find state from CSV state name
     *
     * @param  Collection<int, State>  $states
     */
    protected function findState(Collection $states, string $negeri): ?State
    {
        // State name mappings
        $stateMap = [
            'W.P. KUALA LUMPUR' => 'Kuala Lumpur',
            'W.P. PUTRAJAYA' => 'Putrajaya',
            'W.P. LABUAN' => 'Labuan',
            'N. SEMBILAN' => 'Negeri Sembilan',
            'PULAU PINANG' => 'Penang',
            'MELAKA' => 'Malacca',
            'MELAKA TENGAH' => 'Malacca',
        ];

        // Use mapping if available
        $searchName = $stateMap[strtoupper($negeri)] ?? $negeri;

        return $states->first(function (State $state) use ($searchName, $negeri): bool {
            $stateLower = strtolower((string) $state->name);
            $searchLower = strtolower($searchName);
            $negeriLower = strtolower($negeri);

            return Str::contains($stateLower, $searchLower) ||
                   Str::contains($searchLower, $stateLower) ||
                   Str::contains($stateLower, $negeriLower) ||
                   Str::contains($negeriLower, $stateLower);
        });
    }

    /**
     * Clean phone number
     */
    protected function cleanPhoneNumber(string $phone): ?string
    {
        // Remove all non-digit characters
        $cleaned = preg_replace('/[^0-9]/', '', $phone);

        // Must have at least 7 digits
        if (strlen((string) $cleaned) < 7) {
            return null;
        }

        // Format Malaysian phone numbers
        if (strlen((string) $cleaned) === 7) {
            // Landline without area code, add 03 (KL area code)
            $cleaned = '03'.$cleaned;
        }

        // Add + if it starts with country code
        if (strlen($cleaned) > 10 && str_starts_with($cleaned, '60')) {
            $cleaned = '+'.$cleaned;
        } elseif (strlen($cleaned) === 9 || strlen($cleaned) === 10) {
            // Add Malaysian country code for mobile numbers
            $cleaned = str_starts_with($cleaned, '0') ? '+6'.substr($cleaned, 1) : '+60'.$cleaned;
        }

        return $cleaned;
    }
}
