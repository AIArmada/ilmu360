<?php

declare(strict_types=1);

namespace App\Features\Communications\Actions;

use App\Features\Communications\Support\Normalization\NotificationPreferenceNormalizer;
use App\Features\Communications\Support\Recipient\NotificationRecipientSnapshot;
use Lorisleiva\Actions\Concerns\AsAction;

final class SyncProfileNotificationSettingsAction
{
    use AsAction;

    public function __construct(
        private readonly EnsureNotificationPreferencesAction $ensurePreferences,
        private readonly SyncNotificationPreferenceAction $syncPreference,
        private readonly SyncSystemEmailDestinationAction $syncEmailDestination,
    ) {}

    public function handle(NotificationRecipientSnapshot $recipient): void
    {
        $this->ensurePreferences->handle($recipient);

        $match = [
            'recipient_type' => $recipient->recipientType,
            'recipient_id' => $recipient->id,
            'channel' => null,
            'category' => null,
            'scope_type' => null,
        ];

        $this->syncPreference->handle($match, [
            'timezone' => NotificationPreferenceNormalizer::timezone(
                NotificationPreferenceNormalizer::userTimezone($recipient->timezone),
            ),
        ]);

        $this->syncEmailDestination->handle($recipient);
    }
}
