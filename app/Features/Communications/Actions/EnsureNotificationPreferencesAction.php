<?php

declare(strict_types=1);

namespace App\Features\Communications\Actions;

use AIArmada\Communications\Models\CommunicationPreference;
use App\Features\Communications\Api\NotificationCatalog;
use App\Features\Communications\Api\NotificationFamily;
use App\Features\Communications\Api\NotificationRuleScope;
use App\Features\Communications\Support\Normalization\NotificationPreferenceNormalizer;
use App\Features\Communications\Support\Recipient\NotificationRecipientSnapshot;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

final class EnsureNotificationPreferencesAction
{
    use AsAction;

    public function __construct(
        private readonly SyncNotificationPreferenceAction $syncPreference,
        private readonly SyncSystemEmailDestinationAction $syncEmailDestination,
    ) {}

    public function handle(NotificationRecipientSnapshot $recipient): void
    {
        $baseMatch = [
            'recipient_type' => $recipient->recipientType,
            'recipient_id' => $recipient->id,
            'channel' => null,
            'category' => null,
            'scope_type' => null,
        ];

        $baseExists = CommunicationPreference::query()
            ->where('recipient_type', $recipient->recipientType)
            ->where('recipient_id', $recipient->id)
            ->whereNull('channel')
            ->whereNull('category')
            ->whereNull('scope_type')
            ->exists();

        if (! $baseExists) {
            $this->syncPreference->handle($baseMatch, [
                'locale' => app()->getLocale(),
                'timezone' => NotificationPreferenceNormalizer::userTimezone($recipient->timezone),
                'enabled_at' => now(),
                'metadata' => [
                    'channels' => NotificationPreferenceNormalizer::defaultChannels(),
                ],
            ]);
        }

        $existingScopeKeys = CommunicationPreference::query()
            ->where('recipient_type', $recipient->recipientType)
            ->where('recipient_id', $recipient->id)
            ->whereNotNull('scope_type')
            ->get(['scope_type', 'scope_key'])
            ->groupBy(static fn (CommunicationPreference $preference): string => (string) $preference->scope_type)
            ->map(static fn (Collection $preferences): array => $preferences
                ->pluck('scope_key')
                ->map(static fn (mixed $scopeKey): string => (string) $scopeKey)
                ->all());

        $missingRules = [];
        $timestamp = now();

        foreach (NotificationCatalog::families() as $familyKey => $definition) {
            if (in_array($familyKey, $existingScopeKeys->get(NotificationRuleScope::Family->value, []), true)) {
                continue;
            }

            $missingRules[] = [
                'id' => str()->uuid(),
                'recipient_type' => $recipient->recipientType,
                'recipient_id' => $recipient->id,
                'scope_type' => NotificationRuleScope::Family->value,
                'scope_key' => $familyKey,
                'enabled_at' => $familyKey === NotificationFamily::Sharing->value ? null : $timestamp,
                'source' => 'system',
                'metadata' => json_encode([
                    'cadence' => $definition['default_cadence']->value,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        foreach (NotificationCatalog::triggers() as $triggerKey => $definition) {
            if (in_array($triggerKey, $existingScopeKeys->get(NotificationRuleScope::Trigger->value, []), true)) {
                continue;
            }

            $missingRules[] = [
                'id' => str()->uuid(),
                'recipient_type' => $recipient->recipientType,
                'recipient_id' => $recipient->id,
                'scope_type' => NotificationRuleScope::Trigger->value,
                'scope_key' => $triggerKey,
                'enabled_at' => $timestamp,
                'source' => 'system',
                'metadata' => json_encode([], JSON_THROW_ON_ERROR),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        if ($missingRules !== []) {
            CommunicationPreference::query()->insertOrIgnore($missingRules);
        }

        $this->syncEmailDestination->handle($recipient);
    }
}
