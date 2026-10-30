<?php

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Contacting\Models\SocialProfile;
use App\Enums\SpeakerStatus;
use App\Models\Person;
use Database\Seeders\SpeakerSeeder;
use Illuminate\Support\Facades\DB;

const SEEDED_SPEAKER_ID = '019d5883-0e10-7023-9d05-0040db61e065';

test('speaker seeder imports the embedded production speakers', function () {
    AddressCountry::factory()->create(['iso2' => 'MG', 'iso3' => 'MDG', 'name' => 'Madagascar']);

    $this->seed(SpeakerSeeder::class);

    $person = Person::query()->findOrFail(SEEDED_SPEAKER_ID);
    $searchTermCount = DB::table('person_search_terms')->count();

    expect(Person::query()->count())->toBe(117)
        ->and(Address::query()->count())->toBe(117)
        ->and(SocialProfile::query()->count())->toBe(19)
        ->and($searchTermCount)->toBeGreaterThanOrEqual(525)
        ->and(DB::table('slug_redirects')->count())->toBe(18)
        ->and(DB::table('event_involvements')->count())->toBe(0)
        ->and($person->name)->toBe('Muhadir Haji Joll')
        ->and($person->slug)->toBe('muhadir-haji-joll-my')
        ->and($person->speaker_status)->toBe(SpeakerStatus::Active)
        ->and($person->status)->toBe('verified')
        ->and($person->bio['ms'] ?? null)->toStartWith('Dilahirkan pada 21 Julai 1976')
        ->and($person->primaryAddress()?->country_code)->toBe('MY')
        ->and($person->names()->count())->toBe(2)
        ->and($person->titleAssignments()->count())->toBe(2)
        ->and($person->names()->where('name_type', 'display')->value('full_name'))->toBe('Muhadir Haji Joll')
        ->and($person->names()->where('name_type', 'professional')->value('full_name'))->toBe('Ustaz Tuan Guru Muhadir Haji Joll')
        ->and(SocialProfile::query()
            ->where('socialable_type', $person->getMorphClass())
            ->where('socialable_id', $person->getKey())
            ->where('platform', 'facebook')
            ->where('handle', 'UstazMuhadirOfficial')
            ->count())->toBe(1)
        ->and(DB::table('person_search_terms')->where('person_id', $person->getKey())->count())->toBe(6)
        ->and(DB::table('person_search_terms')->where('person_id', $person->getKey())->pluck('term')->sort()->values()->all())
        ->toBe(['guru', 'haji', 'joll', 'muhadir', 'tuan', 'ustaz'])
        ->and(DB::table('slug_redirects')->where('redirectable_id', $person->getKey())->count())->toBe(3);

    $this->seed(SpeakerSeeder::class);

    $person->refresh();

    expect(Person::query()->count())->toBe(117)
        ->and(Address::query()->count())->toBe(117)
        ->and(SocialProfile::query()->count())->toBe(19)
        ->and(DB::table('person_search_terms')->count())->toBeGreaterThanOrEqual($searchTermCount)
        ->and(DB::table('person_search_terms')->select('person_id', 'term')->groupBy('person_id', 'term')->havingRaw('count(*) > 1')->count())->toBe(0)
        ->and(DB::table('slug_redirects')->count())->toBe(18)
        ->and($person->names()->count())->toBe(2)
        ->and($person->titleAssignments()->count())->toBe(2)
        ->and(DB::table('person_search_terms')->where('person_id', $person->getKey())->count())->toBe(6)
        ->and(DB::table('slug_redirects')->where('redirectable_id', $person->getKey())->count())->toBe(3);
});
