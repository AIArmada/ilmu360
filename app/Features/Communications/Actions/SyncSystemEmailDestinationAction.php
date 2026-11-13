<?php

declare(strict_types=1);

namespace App\Features\Communications\Actions;

use AIArmada\Communications\Models\CommunicationDestination;
use App\Features\Communications\Api\NotificationChannel;
use App\Features\Communications\Support\Recipient\NotificationRecipientSnapshot;
use Lorisleiva\Actions\Concerns\AsAction;

final class SyncSystemEmailDestinationAction
{
    use AsAction;

    public function handle(NotificationRecipientSnapshot $recipient): void
    {
        $recipientKeys = [
            'recipient_type' => $recipient->recipientType,
            'recipient_id' => $recipient->id,
        ];

        if ($recipient->email === null) {
            CommunicationDestination::query()
                ->where($recipientKeys)
                ->where('channel', NotificationChannel::Email->value)
                ->delete();

            return;
        }

        $current = CommunicationDestination::query()
            ->where($recipientKeys)
            ->where('channel', NotificationChannel::Email->value)
            ->get()
            ->first(fn (CommunicationDestination $destination): bool => $destination->address === $recipient->email);

        $attributes = [
            'external_id' => null,
            'status' => $recipient->emailVerifiedAt !== null ? 'active' : 'inactive',
            'is_primary' => true,
            'verified_at' => $recipient->emailVerifiedAt,
            'metadata' => ['source' => 'account_email'],
        ];

        if ($current instanceof CommunicationDestination) {
            $current->forceFill($attributes)->save();
            $keepId = $current->getKey();
        } else {
            $current = new CommunicationDestination;
            $current->forceFill([
                ...$recipientKeys,
                'channel' => NotificationChannel::Email->value,
                'address' => $recipient->email,
                ...$attributes,
            ])->save();
            $keepId = $current->getKey();
        }

        CommunicationDestination::query()
            ->where($recipientKeys)
            ->where('channel', NotificationChannel::Email->value)
            ->where('id', '!=', $keepId)
            ->delete();
    }
}
