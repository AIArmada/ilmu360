<?php

use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\Reference;
use App\Models\Report;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('uses moderation transition when a high risk event report is submitted', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
    ]);

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/reports', [
        'entity_type' => 'event',
        'entity_id' => $event->id,
        'category' => 'donation_scam',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.id', fn ($value) => is_string($value) && $value !== '')
        ->assertJsonPath('message', 'Report submitted successfully. Our team will review it.')
        ->assertJsonPath('meta.request_id', fn ($value) => is_string($value) && $value !== '');

    $event->refresh();

    expect((string) $event->status)->toBe('pending');

    $this->assertDatabaseHas('moderation_reviews', [
        'event_id' => $event->id,
        'decision' => 'remoderated',
    ]);
});

it('prevents duplicate anonymous reports from the same reporter fingerprint within 24 hours', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
    ]);

    $payload = [
        'entity_type' => 'event',
        'entity_id' => $event->id,
        'category' => 'wrong_info',
    ];

    $this->actingAs($user, 'sanctum')
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->withHeaders(['User-Agent' => 'ilmu360-Test-Agent'])
        ->postJson('/api/v1/reports', $payload)
        ->assertCreated();

    $this->actingAs($user, 'sanctum')
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->withHeaders(['User-Agent' => 'ilmu360-Test-Agent'])
        ->postJson('/api/v1/reports', $payload)
        ->assertStatus(409);

    $this->assertDatabaseCount('reports', 1);
});

it('escalates when two distinct anonymous reporters submit reports within 24 hours', function () {
    $firstReporter = User::factory()->create();
    $secondReporter = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
    ]);

    $payload = [
        'entity_type' => 'event',
        'entity_id' => $event->id,
        'category' => 'wrong_info',
    ];

    $this->actingAs($firstReporter, 'sanctum')
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])
        ->withHeaders(['User-Agent' => 'ilmu360-Guest-1'])
        ->postJson('/api/v1/reports', $payload)
        ->assertCreated();

    $this->actingAs($secondReporter, 'sanctum')
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.12'])
        ->withHeaders(['User-Agent' => 'ilmu360-Guest-2'])
        ->postJson('/api/v1/reports', $payload)
        ->assertCreated();

    $event->refresh();

    expect((string) $event->status)->toBe('pending');

    $this->assertDatabaseHas('moderation_reviews', [
        'event_id' => $event->id,
        'decision' => 'remoderated',
    ]);
});

it('requires authentication for api report submission', function () {
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
    ]);

    $this->postJson('/api/v1/reports', [
        'entity_type' => 'event',
        'entity_id' => $event->id,
        'category' => 'wrong_info',
    ])->assertUnauthorized();
});

it('forbids users banned from directory feedback from api report submission', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('feedback.blocked');
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/reports', [
            'entity_type' => 'event',
            'entity_id' => $event->id,
            'category' => 'wrong_info',
        ])
        ->assertForbidden();
});

it('reflects direct feedback permissions on an existing bearer token', function () {
    $user = User::factory()->create();
    $firstEvent = Event::factory()->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
    ]);
    $secondEvent = Event::factory()->create([
        'status' => 'approved',
        'visibility' => EventVisibility::Public,
    ]);

    $token = $user->createToken('feedback-permission-drift-check', [])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/reports', [
            'entity_type' => 'event',
            'entity_id' => $firstEvent->id,
            'category' => 'wrong_info',
        ])
        ->assertCreated();

    $user->givePermissionTo('feedback.blocked');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->withToken($token)
        ->postJson('/api/v1/reports', [
            'entity_type' => 'event',
            'entity_id' => $secondEvent->id,
            'category' => 'wrong_info',
        ])
        ->assertForbidden();

    $user->revokePermissionTo('feedback.blocked');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->withToken($token)
        ->postJson('/api/v1/reports', [
            'entity_type' => 'event',
            'entity_id' => $secondEvent->id,
            'category' => 'wrong_info',
        ])
        ->assertCreated();
});

it('accepts shared reference report categories through the api controller', function () {
    $user = User::factory()->create();
    $reference = Reference::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/reports', [
            'entity_type' => 'reference',
            'entity_id' => $reference->id,
            'category' => 'fake_reference',
        ])
        ->assertCreated();

    $this->assertDatabaseHas('reports', [
        'entity_type' => 'reference',
        'entity_id' => $reference->id,
        'report_type' => 'fake_reference',
    ]);
});

it('stores report evidence uploads through the api controller', function () {
    Storage::fake((string) config('media-library.disk_name', 'public'));

    $user = User::factory()->create();
    $reference = Reference::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->post('/api/v1/reports', [
            'entity_type' => 'reference',
            'entity_id' => $reference->id,
            'category' => 'fake_reference',
            'evidence' => [
                UploadedFile::fake()->image('evidence.png', 600, 400),
                UploadedFile::fake()->createWithContent('details.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"),
            ],
        ], [
            'Accept' => 'application/json',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.id', fn ($value) => is_string($value) && $value !== '')
        ->assertJsonCount(2, 'data.evidence')
        ->assertJsonPath('data.evidence.0.file_name', fn ($value) => is_string($value) && $value !== '');

    $report = Report::query()
        ->where('entity_type', 'reference')
        ->where('entity_id', $reference->id)
        ->firstOrFail();

    expect($report->getMedia('evidence'))->toHaveCount(2);
});
