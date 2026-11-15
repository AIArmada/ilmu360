<?php

use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Person;
use Database\Seeders\ProductionSpeakerSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

afterEach(function (): void {
    DB::purge('production_speaker_import_source');
    config()->set('database.connections.production_speaker_import_source', null);
});

it('imports only event-level person involvements with their canonical fields', function (): void {
    $sourceConnectionName = 'production_speaker_import_source';

    config()->set("database.connections.{$sourceConnectionName}", [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);

    DB::purge($sourceConnectionName);
    $source = DB::connection($sourceConnectionName);

    Schema::connection($sourceConnectionName)->create('events', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('slug');
    });

    Schema::connection($sourceConnectionName)->create('event_involvements', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('event_id');
        $table->string('event_occurrence_id')->nullable();
        $table->string('event_session_id')->nullable();
        $table->string('involveable_type')->nullable();
        $table->string('involveable_id')->nullable();
        $table->string('role_code')->nullable();
        $table->string('display_name')->nullable();
        $table->string('status')->nullable();
        $table->string('visibility')->nullable();
        $table->integer('sort_order')->default(0);
        $table->text('notes')->nullable();
        $table->timestamps();
    });

    $event = Event::factory()->create(['slug' => 'archive-event']);
    $person = Person::factory()->create();
    $sourceSpeaker = (object) ['id' => $person->getKey()];
    $createdAt = '2026-01-01 00:00:00';
    $updatedAt = '2026-01-02 00:00:00';

    $source->table('events')->insert([
        'id' => 'source-event-id',
        'slug' => $event->slug,
    ]);

    $source->table('event_involvements')->insert([
        [
            'id' => 'source-event-level-involvement',
            'event_id' => 'source-event-id',
            'event_occurrence_id' => null,
            'event_session_id' => null,
            'involveable_type' => 'person',
            'involveable_id' => $person->getKey(),
            'role_code' => 'moderator',
            'display_name' => 'Archive moderator',
            'status' => 'inactive',
            'visibility' => 'private',
            'sort_order' => 7,
            'notes' => 'Imported event-level involvement',
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ],
        [
            'id' => 'source-occurrence-involvement',
            'event_id' => 'source-event-id',
            'event_occurrence_id' => 'source-occurrence-id',
            'event_session_id' => null,
            'involveable_type' => 'person',
            'involveable_id' => $person->getKey(),
            'role_code' => 'speaker',
            'display_name' => 'Occurrence speaker',
            'status' => 'active',
            'visibility' => 'public',
            'sort_order' => 8,
            'notes' => null,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ],
        [
            'id' => 'source-session-involvement',
            'event_id' => 'source-event-id',
            'event_occurrence_id' => null,
            'event_session_id' => 'source-session-id',
            'involveable_type' => 'person',
            'involveable_id' => $person->getKey(),
            'role_code' => 'imam',
            'display_name' => 'Session imam',
            'status' => 'active',
            'visibility' => 'public',
            'sort_order' => 9,
            'notes' => null,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ],
        [
            'id' => 'source-non-person-involvement',
            'event_id' => 'source-event-id',
            'event_occurrence_id' => null,
            'event_session_id' => null,
            'involveable_type' => 'institution',
            'involveable_id' => $person->getKey(),
            'role_code' => 'imam',
            'display_name' => 'Institution imam',
            'status' => 'active',
            'visibility' => 'public',
            'sort_order' => 10,
            'notes' => null,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ],
    ]);

    EventKeyPerson::query()->forceCreate([
        'event_id' => $event->getKey(),
        'event_occurrence_id' => (string) Str::uuid(),
        'involveable_type' => 'person',
        'involveable_id' => $person->getKey(),
        'role_code' => 'moderator',
        'status' => 'active',
        'visibility' => 'public',
        'prominence' => '0',
        'is_featured' => false,
        'is_primary' => false,
        'sort_order' => 0,
    ]);

    $importEventLinks = new ReflectionMethod(ProductionSpeakerSeeder::class, 'importEventLinks');
    $seeder = new ProductionSpeakerSeeder;
    $importEventLinks->invoke($seeder, $source, $sourceSpeaker, $person);

    $this->assertDatabaseHas('event_involvements', [
        'event_id' => $event->getKey(),
        'event_occurrence_id' => null,
        'event_session_id' => null,
        'involveable_type' => 'person',
        'involveable_id' => $person->getKey(),
        'role_code' => 'moderator',
        'status' => 'inactive',
        'visibility' => 'private',
        'sort_order' => 7,
        'display_name' => 'Archive moderator',
        'notes' => 'Imported event-level involvement',
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
    ]);

    $importEventLinks->invoke($seeder, $source, $sourceSpeaker, $person);

    expect(EventKeyPerson::query()
        ->where('event_id', $event->getKey())
        ->whereNull('event_occurrence_id')
        ->whereNull('event_session_id')
        ->count())->toBe(1);
});
