<?php

use AIArmada\Communications\Enums\CommunicationCategory;
use AIArmada\Communications\Enums\CommunicationDirection;
use AIArmada\Communications\Enums\CommunicationPriority;
use AIArmada\Communications\Enums\CommunicationStatus;
use AIArmada\Communications\Models\Communication;

it('queues scheduled communications that are due', function () {
    $this->freezeTime();

    $communication = Communication::query()->forceCreate([
        'direction' => CommunicationDirection::Outbound,
        'category' => CommunicationCategory::Transactional,
        'priority' => CommunicationPriority::Normal,
        'purpose' => 'due-notification',
        'status' => CommunicationStatus::Scheduled,
        'scheduled_at' => now()->subMinute(),
    ]);

    $this->artisan('communications:dispatch-due')
        ->assertSuccessful();

    $queuedCommunication = Communication::query()->findOrFail($communication->getKey());

    expect($queuedCommunication->status)->toBe(CommunicationStatus::Queued);
    expect($queuedCommunication->queued_at)->not->toBeNull();
});
