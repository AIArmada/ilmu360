<?php

declare(strict_types=1);

namespace App\Features\Communications\Services;

use AIArmada\Communications\Models\CommunicationDestination;
use AIArmada\Communications\Models\CommunicationPreference;
use App\Features\Communications\Actions\EnsureNotificationPreferencesAction;
use App\Features\Communications\Actions\ResolveNotificationAccountAction;
use App\Features\Communications\Actions\SaveNotificationPreferencesAction;
use App\Features\Communications\Actions\SyncProfileNotificationSettingsAction;
use App\Features\Communications\Actions\SyncSystemEmailDestinationAction;
use App\Features\Communications\Actions\UpdateNotificationLocaleAction;
use App\Features\Communications\Api\AccountNotificationPreferences;
use App\Features\Communications\Api\NotificationCadence;
use App\Features\Communications\Api\NotificationCatalog;
use App\Features\Communications\Api\NotificationChannel;
use App\Features\Communications\Api\NotificationRuleScope;
use App\Features\Communications\Api\NotificationTrigger;
use App\Features\Communications\Api\ResolvedNotificationPolicy;
use App\Features\Communications\Support\Normalization\NotificationPreferenceNormalizer;
use App\Features\Communications\Support\Recipient\NotificationRecipientSnapshot;
use App\Shared\Auth\UserMorphType;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class NotificationSettingsService implements AccountNotificationPreferences
{
    public function __construct(
        private readonly ResolveNotificationAccountAction $resolveAccount,
        private readonly EnsureNotificationPreferencesAction $ensurePreferences,
        private readonly SaveNotificationPreferencesAction $savePreferences,
        private readonly SyncProfileNotificationSettingsAction $syncProfileSettings,
        private readonly SyncSystemEmailDestinationAction $syncEmailDestination,
        private readonly UpdateNotificationLocaleAction $updateLocaleAction,
        private readonly UserMorphType $userMorphType,
    ) {}

    /**
     * @return array{
     *     settings: array<string, mixed>,
     *     families: array<string, array<string, mixed>>,
     *     triggers: array<string, array<string, mixed>>,
     *     grouped_triggers: array<string, list<array<string, mixed>>>,
     *     destinations: array<string, mixed>,
     *     options: array<string, mixed>
     * }
     */
    public function stateFor(string $accountId): array
    {
        $recipient = $this->resolveAccount->handle($accountId);
        $this->ensurePreferences->handle($recipient);

        $setting = $this->basePreferenceQuery($recipient)->firstOrFail();
        $rules = $this->scopePreferencesFor($recipient)->get();

        $familyRuleMap = $rules
            ->where('scope_type', NotificationRuleScope::Family->value)
            ->keyBy('scope_key');
        $triggerRuleMap = $rules
            ->where('scope_type', NotificationRuleScope::Trigger->value)
            ->keyBy('scope_key');

        $families = [];

        foreach (NotificationCatalog::families() as $familyKey => $definition) {
            $rule = $familyRuleMap->get($familyKey);
            $ruleMetadata = $rule instanceof CommunicationPreference ? $this->metaArray($rule) : [];

            $families[$familyKey] = [
                'scope_key' => $familyKey,
                'enabled' => $rule !== null ? ($rule->enabled_at !== null) : true,
                'cadence' => (string) ($ruleMetadata['cadence'] ?? $definition['default_cadence']->value),
                'label' => $definition['label'],
                'description' => $definition['description'],
                'triggers' => $definition['triggers'],
            ];
        }

        $triggers = [];
        $groupedTriggers = [];

        foreach (NotificationCatalog::triggers() as $triggerKey => $definition) {
            $rule = $triggerRuleMap->get($triggerKey);
            $familyKey = $definition['family']->value;

            $triggerState = [
                'scope_key' => $triggerKey,
                'family' => $familyKey,
                'enabled' => $rule !== null ? ($rule->enabled_at !== null) : true,
                'label' => $definition['label'],
                'description' => $definition['description'],
            ];

            $triggers[$triggerKey] = $triggerState;
            $groupedTriggers[$familyKey] ??= [];
            $groupedTriggers[$familyKey][] = $triggerState;
        }

        $metadata = is_array($setting->metadata) ? $setting->metadata : [];

        return [
            'settings' => [
                'timezone' => NotificationPreferenceNormalizer::userTimezone($recipient->timezone),
                'quiet_hours_start' => (string) ($setting->quiet_hours_start ?? ''),
                'quiet_hours_end' => (string) ($setting->quiet_hours_end ?? ''),
                'channels' => NotificationPreferenceNormalizer::channels($metadata['channels'] ?? null),
            ],
            'families' => $families,
            'triggers' => $triggers,
            'grouped_triggers' => $groupedTriggers,
            'destinations' => $this->destinationState($recipient),
            'options' => [
                'channels' => collect(NotificationChannel::userSelectable())
                    ->map(static fn (NotificationChannel $channel): array => [
                        'value' => $channel->value,
                        'label' => $channel->label(),
                    ])
                    ->values()
                    ->all(),
                'cadences' => [
                    NotificationCadence::Instant->value => __('notifications.options.cadence.instant'),
                    NotificationCadence::Daily->value => __('notifications.options.cadence.daily'),
                    NotificationCadence::Weekly->value => __('notifications.options.cadence.weekly'),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function save(string $accountId, array $payload): array
    {
        $recipient = $this->resolveAccount->handle($accountId);
        $this->savePreferences->handle($recipient, $payload);

        return $this->stateFor($accountId);
    }

    public function syncProfileSettings(string $accountId): void
    {
        $this->syncProfileSettings->handle($this->resolveAccount->handle($accountId));
    }

    public function syncSystemEmailDestination(string $accountId): void
    {
        $this->syncEmailDestination->handle($this->resolveAccount->handle($accountId));
    }

    public function updateLocale(string $accountId, string $locale): void
    {
        $this->updateLocaleAction->handle($accountId, $locale);
    }

    public function preferredLocale(string $accountId): string
    {
        $locale = $this->basePreferenceForAccount($accountId)?->locale;

        return is_string($locale) && $locale !== ''
            ? $locale
            : (string) config('app.locale');
    }

    public function preferredTimezone(string $accountId, ?string $fallback = null): string
    {
        $timezone = $this->basePreferenceForAccount($accountId)?->timezone;

        if (is_string($timezone) && $timezone !== '') {
            return $timezone;
        }

        return is_string($fallback) && $fallback !== ''
            ? $fallback
            : (string) config('app.timezone', 'UTC');
    }

    public function resolvePolicy(string $accountId, NotificationTrigger $trigger): ResolvedNotificationPolicy
    {
        $recipient = $this->resolveAccount->handle($accountId);
        $this->ensurePreferences->handle($recipient);

        $setting = $this->basePreferenceQuery($recipient)->firstOrFail();
        $triggerDefinition = NotificationCatalog::triggerDefinition($trigger);
        $familyDefinition = NotificationCatalog::familyDefinition($triggerDefinition['family']);

        $familyRule = $this->scopePreferencesFor($recipient)
            ->where('scope_type', NotificationRuleScope::Family->value)
            ->where('scope_key', $triggerDefinition['family']->value)
            ->firstOrFail();
        $triggerRule = $this->scopePreferencesFor($recipient)
            ->where('scope_type', NotificationRuleScope::Trigger->value)
            ->where('scope_key', $trigger->value)
            ->firstOrFail();

        $familyMetadata = $this->metaArray($familyRule);
        $metadata = is_array($setting->metadata) ? $setting->metadata : [];

        $cadence = NotificationCadence::tryFrom((string) ($familyMetadata['cadence'] ?? $familyDefinition['default_cadence']->value))
            ?? $familyDefinition['default_cadence'];

        return new ResolvedNotificationPolicy(
            family: $triggerDefinition['family'],
            trigger: $trigger,
            enabled: $familyRule->enabled_at !== null
                && $triggerRule->enabled_at !== null
                && $cadence !== NotificationCadence::Off,
            cadence: $cadence,
            channels: NotificationPreferenceNormalizer::channels($metadata['channels'] ?? null),
            quietHoursStart: NotificationPreferenceNormalizer::time($setting->quiet_hours_start),
            quietHoursEnd: NotificationPreferenceNormalizer::time($setting->quiet_hours_end),
            digestDeliveryTime: NotificationPreferenceNormalizer::time(config('notification-center.defaults.digest_delivery_time')),
            digestWeeklyDay: (int) config('notification-center.defaults.digest_weekly_day', 1),
            locale: (string) ($setting->locale ?: app()->getLocale()),
            timezone: NotificationPreferenceNormalizer::userTimezone($recipient->timezone),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function destinationState(NotificationRecipientSnapshot $recipient): array
    {
        /** @var Collection<int, CommunicationDestination> $destinations */
        $destinations = CommunicationDestination::query()
            ->where('recipient_type', $recipient->recipientType)
            ->where('recipient_id', $recipient->id)
            ->get();

        return [
            'email' => [
                'available' => $recipient->email !== null,
                'verified' => $recipient->emailVerifiedAt !== null,
                'address' => $recipient->email,
            ],
            'push' => $destinations
                ->where('channel', NotificationChannel::Push->value)
                ->values()
                ->map(fn (CommunicationDestination $destination): array => [
                    'id' => $destination->id,
                    'installation_id' => $destination->address,
                    'device_label' => (string) ($destination->device_label ?? __('notifications.destinations.unknown_device')),
                    'platform' => (string) ($destination->platform ?? 'unknown'),
                    'last_seen_at' => $this->dateTimeToIso($destination->last_seen_at),
                ])
                ->all(),
        ];
    }

    /**
     * @return Builder<CommunicationPreference>
     */
    private function basePreferenceQuery(NotificationRecipientSnapshot $recipient): Builder
    {
        return CommunicationPreference::query()
            ->where('recipient_type', $recipient->recipientType)
            ->where('recipient_id', $recipient->id)
            ->whereNull('channel')
            ->whereNull('category')
            ->whereNull('scope_type');
    }

    private function basePreferenceForAccount(string $accountId): ?CommunicationPreference
    {
        return CommunicationPreference::query()
            ->where('recipient_type', $this->userMorphType->morphClass())
            ->where('recipient_id', $accountId)
            ->whereNull('channel')
            ->whereNull('category')
            ->whereNull('scope_type')
            ->first();
    }

    /**
     * @return Builder<CommunicationPreference>
     */
    private function scopePreferencesFor(NotificationRecipientSnapshot $recipient): Builder
    {
        return CommunicationPreference::query()
            ->where('recipient_type', $recipient->recipientType)
            ->where('recipient_id', $recipient->id)
            ->whereNotNull('scope_type');
    }

    private function dateTimeToIso(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->toIso8601String();
        }

        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value)->toIso8601String();
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function metaArray(CommunicationPreference $preference): array
    {
        $metadata = $preference->metadata;

        return is_array($metadata) ? $metadata : [];
    }
}
