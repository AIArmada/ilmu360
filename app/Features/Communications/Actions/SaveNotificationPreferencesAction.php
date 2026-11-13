<?php

declare(strict_types=1);

namespace App\Features\Communications\Actions;

use AIArmada\Communications\Models\CommunicationPreference;
use App\Features\Communications\Api\NotificationCatalog;
use App\Features\Communications\Api\NotificationRuleScope;
use App\Features\Communications\Support\Normalization\NotificationPreferenceNormalizer;
use App\Features\Communications\Support\Recipient\NotificationRecipientSnapshot;
use Illuminate\Support\Arr;
use Lorisleiva\Actions\Concerns\AsAction;

final class SaveNotificationPreferencesAction
{
    use AsAction;

    public function __construct(
        private readonly EnsureNotificationPreferencesAction $ensurePreferences,
        private readonly SyncNotificationPreferenceAction $syncPreference,
        private readonly SyncSystemEmailDestinationAction $syncEmailDestination,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(NotificationRecipientSnapshot $recipient, array $payload): void
    {
        $this->ensurePreferences->handle($recipient);

        $setting = CommunicationPreference::query()
            ->where('recipient_type', $recipient->recipientType)
            ->where('recipient_id', $recipient->id)
            ->whereNull('channel')
            ->whereNull('category')
            ->whereNull('scope_type')
            ->firstOrFail();

        /** @var array<string, mixed> $settingsInput */
        $settingsInput = Arr::get($payload, 'settings', []);

        $setting->forceFill([
            'locale' => (string) ($setting->locale ?: app()->getLocale()),
            'timezone' => NotificationPreferenceNormalizer::userTimezone($recipient->timezone),
            'quiet_hours_start' => NotificationPreferenceNormalizer::time(Arr::get($settingsInput, 'quiet_hours_start')),
            'quiet_hours_end' => NotificationPreferenceNormalizer::time(Arr::get($settingsInput, 'quiet_hours_end')),
            'metadata' => [
                'channels' => NotificationPreferenceNormalizer::channels(Arr::get($settingsInput, 'channels')),
            ],
        ])->save();

        /** @var array<string, mixed> $familyInput */
        $familyInput = Arr::get($payload, 'families', []);

        foreach (NotificationCatalog::families() as $familyKey => $definition) {
            if (! array_key_exists($familyKey, $familyInput)) {
                continue;
            }

            /** @var array<string, mixed> $state */
            $state = Arr::get($familyInput, $familyKey, []);
            $enabled = (bool) Arr::get($state, 'enabled', true);

            $this->syncPreference->handle([
                'recipient_type' => $recipient->recipientType,
                'recipient_id' => $recipient->id,
                'scope_type' => NotificationRuleScope::Family->value,
                'scope_key' => $familyKey,
            ], [
                'enabled_at' => $enabled ? now() : null,
                'metadata' => [
                    'cadence' => NotificationPreferenceNormalizer::cadence(
                        (string) Arr::get($state, 'cadence', $definition['default_cadence']->value),
                    )->value,
                ],
            ]);
        }

        /** @var array<string, mixed> $triggerInput */
        $triggerInput = Arr::get($payload, 'triggers', []);

        foreach (NotificationCatalog::triggers() as $triggerKey => $definition) {
            if (! array_key_exists($triggerKey, $triggerInput)) {
                continue;
            }

            /** @var array<string, mixed> $state */
            $state = Arr::get($triggerInput, $triggerKey, []);
            $enabled = (bool) Arr::get($state, 'enabled', true);

            $this->syncPreference->handle([
                'recipient_type' => $recipient->recipientType,
                'recipient_id' => $recipient->id,
                'scope_type' => NotificationRuleScope::Trigger->value,
                'scope_key' => $triggerKey,
            ], [
                'enabled_at' => $enabled ? now() : null,
                'metadata' => [],
            ]);
        }

        $this->syncEmailDestination->handle($recipient);
    }
}
