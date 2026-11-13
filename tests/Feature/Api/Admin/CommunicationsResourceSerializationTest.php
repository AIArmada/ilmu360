<?php

use AIArmada\Authz\Models\Role;
use AIArmada\Communications\Enums\CommunicationCategory;
use AIArmada\Communications\Enums\CommunicationDirection;
use AIArmada\Communications\Enums\CommunicationEventSource;
use AIArmada\Communications\Enums\CommunicationPriority;
use AIArmada\Communications\Enums\CommunicationStatus;
use AIArmada\Communications\Enums\DeliveryStatus;
use AIArmada\Communications\Models\Communication;
use AIArmada\Communications\Models\CommunicationDelivery;
use AIArmada\Communications\Models\CommunicationEvent;
use AIArmada\Communications\Models\CommunicationRecipient;
use AIArmada\Communications\Enums\RecipientRole;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

function communicationsSerializationAdmin(): User
{
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    if (! Role::query()->where('name', 'super_admin')->where('guard_name', 'web')->exists()) {
        $role = new Role;
        $role->forceFill([
            'id' => (string) Str::uuid(),
            'name' => 'super_admin',
            'guard_name' => 'web',
        ])->save();
    }

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $admin;
}

it('omits private Communications fields from generic admin records and related rows', function () {
    /** @var Communication $communication */
    $communication = createTestRecord(Communication::class, [
        'direction' => CommunicationDirection::Outbound,
        'category' => CommunicationCategory::Transactional,
        'priority' => CommunicationPriority::Normal,
        'purpose' => 'notification_delivery',
        'status' => CommunicationStatus::Failed,
        'subject_type' => User::class,
        'subject_id' => 'communications-subject-secret',
        'sender_type' => User::class,
        'sender_id' => 'communications-sender-secret',
        'metadata' => ['private_marker' => 'communication-metadata-secret'],
    ]);

    /** @var CommunicationRecipient $recipient */
    $recipient = createTestRecord(CommunicationRecipient::class, [
        'communication_id' => $communication->getKey(),
        'role' => RecipientRole::To,
    ]);

    /** @var CommunicationDelivery $delivery */
    $delivery = createTestRecord(CommunicationDelivery::class, [
        'communication_id' => $communication->getKey(),
        'recipient_id' => $recipient->getKey(),
        'channel' => 'mail',
        'provider' => 'smtp',
        'provider_message_id' => 'provider-message-secret',
        'status' => DeliveryStatus::Failed,
        'destination_ciphertext' => 'encrypted-address-secret',
        'destination_hash' => 'destination-hash-secret',
        'destination_hint' => 'recipient-hint-secret',
        'failure_message' => 'provider-error-secret',
        'metadata' => ['private_marker' => 'delivery-metadata-secret'],
    ]);

    createTestRecord(CommunicationEvent::class, [
        'communication_id' => $communication->getKey(),
        'delivery_id' => $delivery->getKey(),
        'event' => 'provider_failure',
        'source' => CommunicationEventSource::Provider,
        'occurred_at' => now(),
        'received_at' => now(),
        'payload' => ['private_marker' => 'provider-payload-secret'],
        'failure_message' => 'webhook-error-secret',
        'metadata' => ['private_marker' => 'event-metadata-secret'],
    ]);

    Sanctum::actingAs(communicationsSerializationAdmin());

    $this->getJson(route('api.admin.resources.show', [
        'resourceKey' => 'communications',
        'recordKey' => $communication->getKey(),
    ]))
        ->assertOk()
        ->assertJsonPath('data.record.title', 'Communication')
        ->assertJsonMissingPath('data.record.attributes.subject_id')
        ->assertJsonMissingPath('data.record.attributes.sender_id')
        ->assertJsonMissingPath('data.record.attributes.metadata');

    $this->getJson(route('api.admin.resources.show', [
        'resourceKey' => 'communication-deliveries',
        'recordKey' => $delivery->getKey(),
    ]))
        ->assertOk()
        ->assertJsonPath('data.record.title', 'Communication Delivery')
        ->assertJsonPath('data.record.attributes.channel', 'mail')
        ->assertJsonPath('data.record.attributes.status', DeliveryStatus::Failed->value)
        ->assertJsonMissingPath('data.record.attributes.recipient_id')
        ->assertJsonMissingPath('data.record.attributes.provider_message_id')
        ->assertJsonMissingPath('data.record.attributes.destination_ciphertext')
        ->assertJsonMissingPath('data.record.attributes.destination_hash')
        ->assertJsonMissingPath('data.record.attributes.destination_hint')
        ->assertJsonMissingPath('data.record.attributes.failure_message')
        ->assertJsonMissingPath('data.record.attributes.metadata');

    $response = $this->getJson(route('api.admin.resources.related', [
        'resourceKey' => 'communications',
        'recordKey' => $communication->getKey(),
        'relation' => 'events',
    ]))
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Communication Event')
        ->assertJsonMissingPath('data.0.attributes.payload')
        ->assertJsonMissingPath('data.0.attributes.failure_message')
        ->assertJsonMissingPath('data.0.attributes.metadata');

    expect($response->getContent())
        ->not->toContain('provider-payload-secret')
        ->not->toContain('webhook-error-secret')
        ->not->toContain('event-metadata-secret');
});
