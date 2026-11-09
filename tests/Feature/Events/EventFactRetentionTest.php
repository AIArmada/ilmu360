<?php

declare(strict_types=1);

use AIArmada\Events\Models\EventRegistrationAnswer;
use AIArmada\Events\Models\EventRegistrationItem;
use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\DeletedModels\Models\DeletedModel;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

it('retains event facts and detaches only the account links', function (): void {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $registration = Registration::factory()
        ->forRegistrant($user)
        ->create([
            'event_id' => $event->id,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'external_order_id' => 'order-retain-1',
            'total_amount' => 15000,
            'currency' => 'MYR',
        ]);

    $participant = $registration->participants()->where('is_primary', true)->sole();

    $answer = EventRegistrationAnswer::query()->create([
        'event_registration_id' => $registration->id,
        'event_id' => $event->id,
        'field_key' => 'diet',
        'question' => 'Dietary requirement',
        'answer' => 'Vegetarian',
    ]);

    $item = EventRegistrationItem::query()->create([
        'event_registration_id' => $registration->id,
        'event_id' => $event->id,
        'ticket_type_id' => (string) Str::uuid(),
        'quantity' => 1,
        'unit_price' => 15000,
        'total_price' => 15000,
        'currency' => 'MYR',
        'status' => 'paid',
        'external_order_item_id' => 'order-item-retain-1',
    ]);

    $checkin = EventCheckin::factory()->create([
        'event_id' => $event->id,
        'event_registration_id' => $registration->id,
        'event_registration_participant_id' => $participant->id,
        'attendee_type' => $user->getMorphClass(),
        'attendee_id' => $user->id,
    ]);

    $log = $checkin->logs()->create([
        'action' => 'check_in',
        'source' => 'test',
        'occurred_at' => now(),
    ]);

    $userId = (string) $user->id;

    $user->delete();

    $deleted = DeletedModel::query()
        ->where('key', $userId)
        ->where('model', (new User)->getMorphClass())
        ->firstOrFail();

    $snapshot = $deleted->values['deleted_relations_snapshot'];

    expect($snapshot['events']['retained_fact_counts'])->toMatchArray([
        'registrations' => 1,
        'participants' => 1,
        'answers' => 1,
        'items' => 1,
        'checkins' => 1,
        'attendance_logs' => 1,
    ]);

    assertDatabaseHas('event_registrations', [
        'id' => $registration->id,
        'status' => 'confirmed',
        'payment_status' => 'paid',
        'external_order_id' => 'order-retain-1',
        'total_amount' => 15000,
        'currency' => 'MYR',
        'registrant_type' => null,
        'registrant_id' => null,
    ]);
    assertDatabaseHas('event_registration_participants', ['id' => $participant->id]);
    assertDatabaseHas('event_registration_answers', ['id' => $answer->id, 'answer' => 'Vegetarian']);
    assertDatabaseHas('event_registration_items', ['id' => $item->id, 'external_order_item_id' => 'order-item-retain-1']);
    assertDatabaseHas('event_attendances', [
        'id' => $checkin->id,
        'attendee_type' => null,
        'attendee_id' => null,
    ]);
    assertDatabaseHas('event_attendance_logs', ['id' => $log->id, 'action' => 'check_in']);

    User::restoreDeletedUser($userId);

    expect($registration->fresh()?->isForUser($user))->toBeTrue()
        ->and($checkin->fresh()?->attendee_id)->toBe($userId)
        ->and($checkin->fresh()?->attendee_type)->toBe($user->getMorphClass())
        ->and($answer->fresh())->not->toBeNull()
        ->and($item->fresh())->not->toBeNull()
        ->and($log->fresh())->not->toBeNull();
});

it('restores a registration transferred while the account was deleted', function (): void {
    $user = User::factory()->create();
    $newOwner = User::factory()->create();
    $event = Event::factory()->create();

    $registration = Registration::factory()
        ->forRegistrant($user)
        ->create(['event_id' => $event->id]);

    $user->delete();

    $registration = $registration->fresh();

    $registration->forceFill([
        'registrant_type' => $newOwner->getMorphClass(),
        'registrant_id' => $newOwner->id,
    ])->saveQuietly();

    expect($registration->fresh()?->registrant_id)->toBe($newOwner->id)
        ->and($registration->fresh()?->registrant_type)->toBe($newOwner->getMorphClass());

    User::restoreDeletedUser($user->id);

    expect($registration->fresh()?->registrant_id)->toBe($newOwner->id)
        ->and($registration->fresh()?->registrant_type)->toBe($newOwner->getMorphClass());
});
