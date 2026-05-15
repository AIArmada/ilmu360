<?php

use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\TimingMode;
use App\Models\Event;
use App\Models\SavedSearch;
use App\Models\Speaker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

describe('Saved Search API Endpoints', function () {
    it('requires authentication', function () {
        $this->getJson('/api/v1/saved-searches')
            ->assertUnauthorized();
    });

    describe('Authenticated endpoints', function () {
        beforeEach(function () {
            $this->user = User::factory()->create();
            Sanctum::actingAs($this->user);
        });

        describe('GET /api/v1/saved-searches', function () {
            it('returns user saved searches', function () {
                SavedSearch::factory()->count(3)->create(['user_id' => $this->user->id]);
                SavedSearch::factory()->count(2)->create(); // Other user's searches

                $response = $this->getJson('/api/v1/saved-searches');

                $response->assertOk()
                    ->assertJsonCount(3, 'data');
            });
        });

        describe('POST /api/v1/saved-searches', function () {
            it('creates a saved search', function () {
                $imamSpeaker = Speaker::factory()->create();
                $personInChargeSpeaker = Speaker::factory()->create();

                $response = $this->postJson('/api/v1/saved-searches', [
                    'name' => 'Kuliah Maghrib',
                    'query' => 'maghrib',
                    'filters' => [
                        'language_codes' => ['ms'],
                        'key_person_roles' => [EventKeyPersonRole::Imam->value],
                        'person_in_charge_ids' => [$personInChargeSpeaker->id],
                        'person_in_charge_search' => 'Penyelaras Saf',
                        'imam_ids' => [$imamSpeaker->id],
                        'starts_on_local_date' => '2026-04-12',
                    ],
                    'notify' => 'daily',
                ]);

                $response->assertCreated()
                    ->assertJsonPath('data.name', 'Kuliah Maghrib')
                    ->assertJsonPath('data.filters.key_person_roles.0', EventKeyPersonRole::Imam->value)
                    ->assertJsonPath('data.filters.person_in_charge_ids.0', $personInChargeSpeaker->id)
                    ->assertJsonPath('data.filters.person_in_charge_search', 'Penyelaras Saf')
                    ->assertJsonPath('data.filters.imam_ids.0', $imamSpeaker->id)
                    ->assertJsonPath('data.filters.starts_on_local_date', '2026-04-12');

                $this->assertDatabaseHas('saved_searches', [
                    'user_id' => $this->user->id,
                    'name' => 'Kuliah Maghrib',
                ]);
            });

            it('stores saved search enum filters as backing values', function () {
                $response = $this->postJson('/api/v1/saved-searches', [
                    'name' => 'Enum Filter Search',
                    'filters' => [
                        'event_type' => [EventType::KuliahCeramah->value],
                        'event_format' => [EventFormat::Online->value],
                        'gender' => EventGenderRestriction::All->value,
                        'age_group' => [EventAgeGroup::AllAges->value],
                        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
                        'timing_mode' => TimingMode::PrayerRelative->value,
                    ],
                    'notify' => 'daily',
                ]);

                $response->assertCreated()
                    ->assertJsonPath('data.filters.event_type.0', EventType::KuliahCeramah->value)
                    ->assertJsonPath('data.filters.event_format.0', EventFormat::Online->value)
                    ->assertJsonPath('data.filters.gender', EventGenderRestriction::All->value)
                    ->assertJsonPath('data.filters.age_group.0', EventAgeGroup::AllAges->value)
                    ->assertJsonPath('data.filters.prayer_time', EventPrayerTime::SelepasMaghrib->value)
                    ->assertJsonPath('data.filters.timing_mode', TimingMode::PrayerRelative->value);
            });

            it('stores saved search scalar filters in canonical forms', function () {
                $response = $this->postJson('/api/v1/saved-searches', [
                    'name' => 'Scalar Filter Search',
                    'filters' => [
                        'starts_after' => '2026-04-23',
                        'starts_time_from' => '8:05',
                        'children_allowed' => 'yes',
                        'has_live_url' => '0',
                        'person_in_charge_search' => '  Penyelaras Saf  ',
                        'time_scope' => 'all',
                    ],
                    'notify' => 'daily',
                ]);

                $response->assertCreated()
                    ->assertJsonPath('data.filters.starts_after', '2026-04-23')
                    ->assertJsonPath('data.filters.starts_time_from', '08:05')
                    ->assertJsonPath('data.filters.children_allowed', true)
                    ->assertJsonPath('data.filters.has_live_url', false)
                    ->assertJsonPath('data.filters.person_in_charge_search', 'Penyelaras Saf')
                    ->assertJsonPath('data.filters.time_scope', 'all');
            });

            it('rejects saved search enum filter display labels', function () {
                $response = $this->postJson('/api/v1/saved-searches', [
                    'name' => 'Label Filter Search',
                    'filters' => [
                        'event_type' => ['Kuliah / Ceramah'],
                        'event_format' => ['Physical'],
                        'gender' => 'Lelaki Sahaja',
                        'age_group' => ['Semua Peringkat Umur'],
                        'prayer_time' => 'Selepas Maghrib',
                        'timing_mode' => 'Prayer Time',
                    ],
                    'notify' => 'daily',
                ]);

                $response->assertUnprocessable()
                    ->assertJsonValidationErrors([
                        'filters.event_type.0',
                        'filters.event_format.0',
                        'filters.gender',
                        'filters.age_group.0',
                        'filters.prayer_time',
                        'filters.timing_mode',
                    ]);
            });

            it('repairs legacy saved search enum labels into backing values', function () {
                $savedSearch = SavedSearch::factory()->create([
                    'user_id' => $this->user->id,
                    'filters' => [
                        'event_type' => ['Forum Perdana'],
                        'event_format' => ['Physical'],
                        'gender' => 'Lelaki Sahaja',
                        'age_group' => ['Semua Peringkat Umur'],
                        'key_person_roles' => ['Penceramah', 'PIC / Penyelaras'],
                        'prayer_time' => 'Selepas Maghrib',
                        'timing_mode' => 'Prayer Time',
                    ],
                ]);

                runLegacySavedSearchEnumFilterRepairMigration();

                $filters = $savedSearch->fresh()?->filters;

                expect($filters)->toMatchArray([
                    'event_type' => [EventType::Forum->value],
                    'event_format' => [EventFormat::Physical->value],
                    'gender' => EventGenderRestriction::MenOnly->value,
                    'age_group' => [EventAgeGroup::AllAges->value],
                    'key_person_roles' => [EventKeyPersonRole::PersonInCharge->value],
                    'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
                    'timing_mode' => TimingMode::PrayerRelative->value,
                ]);
            });

            it('rejects the legacy singular language filter key', function () {
                $response = $this->postJson('/api/v1/saved-searches', [
                    'name' => 'Legacy Language Search',
                    'filters' => [
                        'language' => 'malay',
                    ],
                    'notify' => 'daily',
                ]);

                $response->assertUnprocessable()
                    ->assertJsonValidationErrors(['filters']);
            });

            it('validates required fields', function () {
                $response = $this->postJson('/api/v1/saved-searches', []);

                $response->assertUnprocessable()
                    ->assertJsonValidationErrors(['name', 'notify'])
                    ->assertJsonPath('error.code', 'validation_error')
                    ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));
            });

            it('validates notify options', function () {
                $response = $this->postJson('/api/v1/saved-searches', [
                    'name' => 'Test Search',
                    'notify' => 'invalid',
                ]);

                $response->assertUnprocessable()
                    ->assertJsonValidationErrors(['notify']);
            });

            it('validates key person role options', function () {
                $response = $this->postJson('/api/v1/saved-searches', [
                    'name' => 'Role Search',
                    'filters' => [
                        'key_person_roles' => ['invalid-role', EventKeyPersonRole::Speaker->value],
                    ],
                    'notify' => 'daily',
                ]);

                $response->assertUnprocessable()
                    ->assertJsonValidationErrors(['filters.key_person_roles.0', 'filters.key_person_roles.1']);
            });

            it('validates saved search scalar filters that the endpoint advertises', function () {
                $response = $this->postJson('/api/v1/saved-searches', [
                    'name' => 'Strict Filter Search',
                    'filters' => [
                        'institution_id' => 'not-a-uuid',
                        'venue_id' => 'not-a-uuid',
                        'starts_after' => '12-04-2026',
                        'starts_before' => '2026/04/12',
                        'starts_time_from' => 'tomorrow',
                        'starts_time_until' => '25:99',
                        'children_allowed' => 'maybe',
                        'is_muslim_only' => 'sometimes',
                        'has_event_url' => 'later',
                        'has_live_url' => 'later',
                        'has_end_time' => 'later',
                    ],
                    'notify' => 'daily',
                ]);

                $response->assertUnprocessable()
                    ->assertJsonValidationErrors([
                        'filters.institution_id',
                        'filters.venue_id',
                        'filters.starts_after',
                        'filters.starts_before',
                        'filters.starts_time_from',
                        'filters.starts_time_until',
                        'filters.children_allowed',
                        'filters.is_muslim_only',
                        'filters.has_event_url',
                        'filters.has_live_url',
                        'filters.has_end_time',
                    ]);
            });

            it('validates local event date filters', function () {
                $response = $this->postJson('/api/v1/saved-searches', [
                    'name' => 'Local Date Search',
                    'filters' => [
                        'starts_on_local_date' => '12-04-2026',
                    ],
                    'notify' => 'daily',
                ]);

                $response->assertUnprocessable()
                    ->assertJsonValidationErrors(['filters.starts_on_local_date']);
            });

            it('enforces max 10 saved searches per user', function () {
                SavedSearch::factory()->count(10)->create(['user_id' => $this->user->id]);

                $response = $this->postJson('/api/v1/saved-searches', [
                    'name' => 'Eleventh Search',
                    'notify' => 'off',
                ]);

                $response->assertStatus(422)
                    ->assertJsonPath('error.code', 'validation_error')
                    ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));
            });

            it('requires lat/lng when radius_km is provided', function () {
                $response = $this->postJson('/api/v1/saved-searches', [
                    'name' => 'Nearby Search',
                    'radius_km' => 50,
                    'notify' => 'weekly',
                ]);

                $response->assertUnprocessable()
                    ->assertJsonValidationErrors(['lat', 'lng']);
            });

            it('accepts radius up to 1000 km when coordinates are provided', function () {
                $response = $this->postJson('/api/v1/saved-searches', [
                    'name' => 'Wide Nearby Search',
                    'radius_km' => 1000,
                    'lat' => 3.1390,
                    'lng' => 101.6869,
                    'notify' => 'daily',
                ]);

                $response->assertCreated()
                    ->assertJsonPath('data.radius_km', 1000);
            });

            it('rejects non-numeric geography filter ids', function () {
                $response = $this->postJson('/api/v1/saved-searches', [
                    'name' => 'Geography Filter Test',
                    'filters' => [
                        'state_id' => (string) Str::uuid(),
                    ],
                    'notify' => 'daily',
                ]);

                $response->assertUnprocessable()
                    ->assertJsonValidationErrors(['filters.state_id']);
            });

            it('rejects non-numeric subdistrict filter ids', function () {
                $response = $this->postJson('/api/v1/saved-searches', [
                    'name' => 'Subdistrict Filter Test',
                    'filters' => [
                        'subdistrict_id' => (string) Str::uuid(),
                    ],
                    'notify' => 'daily',
                ]);

                $response->assertUnprocessable()
                    ->assertJsonValidationErrors(['filters.subdistrict_id']);
            });
        });

        describe('GET /api/v1/saved-searches/{id}', function () {
            it('returns a specific saved search', function () {
                $search = SavedSearch::factory()->create(['user_id' => $this->user->id]);

                $response = $this->getJson("/api/v1/saved-searches/{$search->id}");

                $response->assertOk()
                    ->assertJsonPath('data.id', $search->id);
            });

            it('denies access to other users searches', function () {
                $otherSearch = SavedSearch::factory()->create();

                $response = $this->getJson("/api/v1/saved-searches/{$otherSearch->id}");

                $response->assertForbidden();
            });
        });

        describe('PUT /api/v1/saved-searches/{id}', function () {
            it('updates a saved search', function () {
                $search = SavedSearch::factory()->create([
                    'user_id' => $this->user->id,
                    'name' => 'Old Name',
                ]);

                $response = $this->putJson("/api/v1/saved-searches/{$search->id}", [
                    'name' => 'New Name',
                    'notify' => 'weekly',
                ]);

                $response->assertOk()
                    ->assertJsonPath('data.name', 'New Name');
            });

            it('prevents updating other users searches', function () {
                $otherSearch = SavedSearch::factory()->create();

                $response = $this->putJson("/api/v1/saved-searches/{$otherSearch->id}", [
                    'name' => 'Hacked',
                ]);

                $response->assertForbidden();
            });
        });

        describe('DELETE /api/v1/saved-searches/{id}', function () {
            it('deletes a saved search', function () {
                $search = SavedSearch::factory()->create(['user_id' => $this->user->id]);

                $response = $this->deleteJson("/api/v1/saved-searches/{$search->id}");

                $response->assertNoContent();
                $this->assertDatabaseMissing('saved_searches', ['id' => $search->id]);
            });

            it('prevents deleting other users searches', function () {
                $otherSearch = SavedSearch::factory()->create();

                $response = $this->deleteJson("/api/v1/saved-searches/{$otherSearch->id}");

                $response->assertForbidden();
                $this->assertDatabaseHas('saved_searches', ['id' => $otherSearch->id]);
            });
        });

        describe('POST /api/v1/saved-searches/{id}/execute', function () {
            it('executes a saved search and returns results', function () {
                $search = SavedSearch::factory()->create([
                    'user_id' => $this->user->id,
                    'query' => 'test',
                    'filters' => ['language_codes' => ['ms']],
                ]);

                $response = $this->postJson("/api/v1/saved-searches/{$search->id}/execute");

                $response->assertOk()
                    ->assertJsonStructure(['data', 'meta'])
                    ->assertJsonPath('meta.pagination.page', 1)
                    ->assertJsonPath('meta.pagination.has_more', false)
                    ->assertJsonPath('meta.pagination.next_page', null)
                    ->assertJsonPath('meta.request_id', fn (string $requestId) => filled($requestId));
            });

            it('executes saved searches with a local event date filter', function () {
                config()->set('scout.driver', 'database');

                Event::factory()->create([
                    'title' => 'Saved Search Local Date Match',
                    'starts_at' => Carbon::parse('2026-04-23 02:00:00', 'UTC'),
                    'status' => 'approved',
                    'visibility' => 'public',
                    'is_active' => true,
                ]);

                Event::factory()->create([
                    'title' => 'Saved Search Local Date Non Match',
                    'starts_at' => Carbon::parse('2026-04-24 02:00:00', 'UTC'),
                    'status' => 'approved',
                    'visibility' => 'public',
                    'is_active' => true,
                ]);

                $search = SavedSearch::factory()->create([
                    'user_id' => $this->user->id,
                    'query' => null,
                    'filters' => [
                        'starts_on_local_date' => '2026-04-23',
                        'time_scope' => 'all',
                    ],
                    'radius_km' => null,
                    'lat' => null,
                    'lng' => null,
                ]);

                $response = $this
                    ->withHeader('X-Timezone', 'Asia/Kuala_Lumpur')
                    ->postJson("/api/v1/saved-searches/{$search->id}/execute");

                $response->assertOk();

                expect(collect($response->json('data'))->pluck('title')->all())
                    ->toContain('Saved Search Local Date Match')
                    ->not->toContain('Saved Search Local Date Non Match');
            });
        });
    });
});

function runLegacySavedSearchEnumFilterRepairMigration(): void
{
    $migration = require base_path('database/migrations/2026_04_23_121000_repair_legacy_saved_search_enum_filters.php');

    assert(is_object($migration) && method_exists($migration, 'up'));

    $migration->up();
}
