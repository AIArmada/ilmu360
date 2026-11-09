<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Institution;
use App\Models\ModerationReview;
use App\Models\Person;
use App\Models\User;
use App\Support\Events\OrganizerResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('normalizes event involvement and moderation actor class names to morph aliases', function (): void {
    $event = Event::factory()->create();
    $person = Person::factory()->create();
    $institution = Institution::factory()->create();
    $user = User::factory()->create();

    DB::table('event_involvements')->insert([
        [
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'involveable_type' => Person::class,
            'involveable_id' => $person->id,
            'role_code' => 'speaker',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'involveable_type' => Institution::class,
            'involveable_id' => $institution->id,
            'role_code' => 'organizer',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    DB::table('moderation_actions')->insert([
        'id' => (string) Str::uuid(),
        'actionable_type' => 'event',
        'actionable_id' => $event->id,
        'actioned_by_type' => User::class,
        'actioned_by_id' => $user->id,
        'type' => 'approve',
        'reason' => 'approved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require database_path('migrations/2026_11_09_000000_normalize_event_involvement_and_moderation_morph_types.php');
    $migration->up();

    expect(DB::table('event_involvements')->where('involveable_type', Person::class)->count())->toBe(0)
        ->and(DB::table('event_involvements')->where('involveable_type', 'person')->count())->toBe(1)
        ->and(DB::table('event_involvements')->where('involveable_type', Institution::class)->count())->toBe(0)
        ->and(DB::table('event_involvements')->where('involveable_type', 'institution')->count())->toBe(1)
        ->and(DB::table('moderation_actions')->where('actioned_by_type', User::class)->count())->toBe(0)
        ->and(DB::table('moderation_actions')->where('actioned_by_type', 'user')->count())->toBe(1);
});

it('writes organizer involvements and moderation actors as morph aliases', function (): void {
    $event = Event::factory()->create();
    $institution = Institution::factory()->create();

    $event->setPrimaryOrganizer($institution);

    $involvement = $event->fresh()->primaryOrganizerInvolvement;

    expect($involvement?->involveable_type)->toBe((new Institution)->getMorphClass())
        ->and(OrganizerResolver::involvementMatchesInstitution($involvement, $institution))->toBeTrue()
        ->and(ModerationReview::factory()->create()->actioned_by_type)->toBe((new User)->getMorphClass());
});

it('resolves legacy fully qualified organizer involvement rows', function (): void {
    $event = Event::factory()->create();
    $institution = Institution::factory()->create();

    DB::table('event_involvements')->insert([
        'id' => (string) Str::uuid(),
        'event_id' => $event->id,
        'involveable_type' => Institution::class,
        'involveable_id' => $institution->id,
        'role_code' => 'organizer',
        'is_primary' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $involvement = $event->fresh()->primaryOrganizerInvolvement;

    expect(OrganizerResolver::involvementMatchesInstitution($involvement, $institution))->toBeTrue();
});
