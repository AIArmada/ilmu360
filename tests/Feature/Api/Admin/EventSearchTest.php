<?php

use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

describe('Event Search API', function () {
    it('requires authentication', function () {
        $this->getJson('/api/v1/admin/events/search')->assertUnauthorized();
    });

    it('requires admin access', function () {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/events/search')->assertForbidden();
    });

    it('returns paginated event results for admin users', function () {
        $admin = eventSearchAdminUser();

        Event::factory()->count(3)->create();

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/events/search?per_page=2&page=1')
            ->assertOk();

        $response->assertJsonStructure([
            'data',
            'meta' => [
                'search' => ['query', 'sort', 'nearby', 'filters'],
                'pagination' => [
                    'page',
                    'per_page',
                    'total',
                    'last_page',
                    'from',
                    'to',
                    'has_more_pages',
                ],
            ],
        ]);
    });

    it('accepts rich event search filters matching mcp contract', function () {
        $admin = eventSearchAdminUser();

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/events/search?query=fiqh&sort=time&time_scope=all&event_format=online,hybrid&age_group=adults,youth&speaker_ids=abc,def&language_codes=ms,en&has_live_url=1')
            ->assertOk();

        expect(data_get($response->json(), 'meta.search.filters.event_format'))->toBe(['online', 'hybrid'])
            ->and(data_get($response->json(), 'meta.search.filters.age_group'))->toBe(['adults', 'youth'])
            ->and(data_get($response->json(), 'meta.search.filters.speaker_ids'))->toBe(['abc', 'def'])
            ->and(data_get($response->json(), 'meta.search.filters.language_codes'))->toBe(['ms', 'en'])
            ->and(data_get($response->json(), 'meta.search.filters.has_live_url'))->toBe('1');
    });

    it('validates query parameters and returns 422 on invalid values', function () {
        $admin = eventSearchAdminUser();

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/events/search?sort=invalid&starts_after=2026/05/03&lat=200')
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'sort',
                'starts_after',
                'lat',
            ]);
    });

    it('falls back to time sort when distance sort lacks coordinates', function () {
        $admin = eventSearchAdminUser();

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/events/search?sort=distance');

        $response->assertOk();

        expect(data_get($response->json(), 'meta.search.sort'))->toBe('time')
            ->and(data_get($response->json(), 'meta.search.nearby.enabled'))->toBeFalse();
    });

    it('searches by institution, speaker, and reference associations', function () {
        $admin = eventSearchAdminUser();

        Sanctum::actingAs($admin);

        $institution = Institution::factory()->create([
            'name' => 'Markaz Ikhlas API Admin',
            'status' => 'verified',
            'is_active' => true,
        ]);

        Event::factory()->create([
            'title' => 'API Admin Institution Match Event',
            'institution_id' => $institution->id,
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDay(),
        ]);

        $speaker = Speaker::factory()->create([
            'name' => 'Ustaz Akram API Admin',
            'status' => 'verified',
            'is_active' => true,
        ]);

        $speakerEvent = Event::factory()->create([
            'title' => 'API Admin Speaker Match Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDay(),
        ]);

        $speakerEvent->keyPeople()->create([
            'speaker_id' => $speaker->id,
            'name' => $speaker->name,
            'role' => 'speaker',
            'order_column' => 1,
        ]);

        $reference = Reference::factory()->create([
            'title' => 'Kitab API Admin Search Reference',
            'status' => 'verified',
            'is_active' => true,
        ]);

        $referenceEvent = Event::factory()->create([
            'title' => 'API Admin Reference Match Event',
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now(),
            'starts_at' => now()->addDay(),
        ]);

        $referenceEvent->references()->attach($reference->id);

        $institutionResponse = $this->getJson('/api/v1/admin/events/search?query=Markaz%20Ikhlas%20API%20Admin&time_scope=all')
            ->assertOk();

        expect(collect(data_get($institutionResponse->json(), 'data', []))->pluck('title')->all())
            ->toContain('API Admin Institution Match Event');

        $speakerResponse = $this->getJson('/api/v1/admin/events/search?query=Akram%20API%20Admin&time_scope=all')
            ->assertOk();

        expect(collect(data_get($speakerResponse->json(), 'data', []))->pluck('title')->all())
            ->toContain('API Admin Speaker Match Event');

        $referenceResponse = $this->getJson('/api/v1/admin/events/search?query=API%20Admin%20Search%20Reference&time_scope=all')
            ->assertOk();

        expect(collect(data_get($referenceResponse->json(), 'data', []))->pluck('title')->all())
            ->toContain('API Admin Reference Match Event');
    });
});

function eventSearchAdminUser(string $role = 'super_admin'): User
{
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    if (! Role::query()->where('name', $role)->where('guard_name', 'web')->exists()) {
        $roleRecord = new Role;
        $roleRecord->forceFill([
            'id' => (string) Str::uuid(),
            'name' => $role,
            'guard_name' => 'web',
        ])->save();
    }

    $user = User::factory()->create();
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}
