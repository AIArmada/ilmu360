<?php

namespace App\Services\Notifications;

use AIArmada\Communications\Models\CommunicationDestination;
use AIArmada\Communications\Models\CommunicationPreference;
use App\Enums\NotificationCadence;
use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Enums\NotificationRuleScope;
use App\Enums\NotificationTrigger;
use App\Models\User;
use App\Support\Notifications\NotificationCatalog;
use App\Support\Notifications\ResolvedNotificationPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class NotificationSettingsManager
{
    public function ensureUserConfiguration(User $user): void
    {
        CommunicationPreference::query()->firstOrCreate(
            [
                'recipient_type' => $user->getMorphClass(),
                'recipient_id' => $user->getKey(),
                'channel' => null,
                'category' => null,
            ],
            [
                'locale' => app()->getLocale(),
                'timezone' => $this->userStringAttribute($user, 'timezone') ?: config('app.timezone'),
                'enabled_at' => now(),
                'metadata' => [
                    'digest_delivery_time' => config('notification-center.defaults.digest_delivery_time'),
                    'digest_weekly_day' => (int) config('notification-center.defaults.digest_weekly_day', 1),
                    'preferred_channels' => config('notification-center.defaults.preferred_channels', []),
                    'fallback_channels' => config('notification-center.defaults.fallback_channels', []),
                    'fallback_strategy' => (string) config('notification-center.defaults.fallback_strategy', 'next_available'),
                    'urgent_override' => true,
                ],
            ]
        );

        $existingScopeKeys = $this->scopePreferencesFor($user)
            ->get(['scope_type', 'scope_key'])
            ->groupBy(fn (CommunicationPreference $pref): string => (string) $pref->scope_type)
            ->map(fn (Collection $prefs): array => $prefs
                ->pluck('scope_key')
                ->map(fn (mixed $scopeKey): string => (string) $scopeKey)
                ->all());

        $missingRules = [];
        $timestamp = now();

        foreach (NotificationCatalog::families() as $familyKey => $definition) {
            if (in_array($familyKey, $existingScopeKeys->get(NotificationRuleScope::Family->value, []), true)) {
                continue;
            }

            $missingRules[] = [
                'id' => str()->uuid(),
                'recipient_type' => $user->getMorphClass(),
                'recipient_id' => $user->getKey(),
                'scope_type' => NotificationRuleScope::Family->value,
                'scope_key' => $familyKey,
                'enabled_at' => $timestamp,
                'source' => 'system',
                'metadata' => json_encode([
                    'cadence' => $definition['default_cadence']->value,
                    'channels' => $definition['default_channels'],
                    'fallback_channels' => $definition['default_channels'],
                    'urgent_override' => null,
                    'inherits_family' => false,
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
                'recipient_type' => $user->getMorphClass(),
                'recipient_id' => $user->getKey(),
                'scope_type' => NotificationRuleScope::Trigger->value,
                'scope_key' => $triggerKey,
                'enabled_at' => $timestamp,
                'source' => 'system',
                'metadata' => json_encode([
                    'cadence' => $definition['default_cadence']->value,
                    'channels' => $definition['default_channels'],
                    'fallback_channels' => $definition['default_channels'],
                    'urgent_override' => null,
                    'inherits_family' => true,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        if ($missingRules !== []) {
            CommunicationPreference::query()->insertOrIgnore($missingRules);
        }

        $this->syncSystemDestinations($user);
    }

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
    public function stateFor(User $user): array
    {
        $this->ensureUserConfiguration($user);

        /** @var CommunicationPreference $setting */
        $setting = $user->notificationSetting()->firstOrFail();
        /** @var Collection<int, CommunicationPreference> $rules */
        $rules = $this->scopePreferencesFor($user)->get();

        $familyRuleMap = $rules
            ->where('scope_type', NotificationRuleScope::Family->value)
            ->keyBy('scope_key');
        $triggerRuleMap = $rules
            ->where('scope_type', NotificationRuleScope::Trigger->value)
            ->keyBy('scope_key');

        $families = [];
        foreach (NotificationCatalog::families() as $familyKey => $definition) {
            $rule = $familyRuleMap->get($familyKey);
            $ruleMeta = $rule instanceof CommunicationPreference ? $this->metaArray($rule) : [];

            $familyCadence = $ruleMeta['cadence'] ?? $definition['default_cadence']->value;
            $familyChannels = $this->normalizeChannels(
                $ruleMeta['channels'] ?? null,
                $definition['allowed_channels'],
                $definition['default_channels'],
            );

            $families[$familyKey] = [
                'scope_key' => $familyKey,
                'enabled' => $rule !== null ? ($rule->enabled_at !== null) : true,
                'cadence' => $familyCadence,
                'channels' => $familyChannels,
                'allowed_channels' => $definition['allowed_channels'],
                'default_channels' => $definition['default_channels'],
                'label' => $definition['label'],
                'description' => $definition['description'],
                'trigger_keys' => $definition['triggers'],
            ];
        }

        $triggers = [];
        $groupedTriggers = [];
        foreach (NotificationCatalog::triggers() as $triggerKey => $definition) {
            $rule = $triggerRuleMap->get($triggerKey);
            $ruleMeta = $rule instanceof CommunicationPreference ? $this->metaArray($rule) : [];
            $familyKey = $definition['family']->value;
            $familyRule = $familyRuleMap->get($familyKey);
            $familyMeta = $familyRule instanceof CommunicationPreference ? $this->metaArray($familyRule) : [];

            $inheritsFamily = isset($ruleMeta['inherits_family'])
                ? (bool) $ruleMeta['inherits_family']
                : true;

            $resolvedCadence = $inheritsFamily
                ? ($familyMeta['cadence'] ?? $definition['default_cadence']->value)
                : ($ruleMeta['cadence'] ?? $definition['default_cadence']->value);

            $resolvedChannels = $inheritsFamily
                ? $this->normalizeChannels(
                    $familyMeta['channels'] ?? null,
                    $definition['allowed_channels'],
                    $definition['default_channels'],
                )
                : $this->normalizeChannels(
                    $ruleMeta['channels'] ?? null,
                    $definition['allowed_channels'],
                    $definition['default_channels'],
                );

            $triggerState = [
                'scope_key' => $triggerKey,
                'family' => $familyKey,
                'enabled' => $rule !== null ? ($rule->enabled_at !== null) : true,
                'inherits_family' => $inheritsFamily,
                'cadence' => $resolvedCadence,
                'channels' => $resolvedChannels,
                'allowed_channels' => $definition['allowed_channels'],
                'default_channels' => $definition['default_channels'],
                'label' => $definition['label'],
                'description' => $definition['description'],
                'priority' => $definition['priority']->value,
                'supports_urgent_override' => in_array($definition['priority'], [
                    NotificationPriority::High,
                    NotificationPriority::Urgent,
                ], true),
                'urgent_override' => $ruleMeta['urgent_override'] ?? null,
            ];

            $triggers[$triggerKey] = $triggerState;
            $groupedTriggers[$familyKey] ??= [];
            $groupedTriggers[$familyKey][] = $triggerState;
        }

        $metadata = is_array($setting->metadata) ? $setting->metadata : [];

        return [
            'settings' => [
                'locale' => (string) ($setting->locale ?: app()->getLocale()),
                'timezone' => (string) ($setting->timezone ?: ($this->userStringAttribute($user, 'timezone') ?: config('app.timezone'))),
                'quiet_hours_start' => (string) ($setting->quiet_hours_start ?? ''),
                'quiet_hours_end' => (string) ($setting->quiet_hours_end ?? ''),
                'digest_delivery_time' => (string) ($metadata['digest_delivery_time'] ?? config('notification-center.defaults.digest_delivery_time')),
                'digest_weekly_day' => (int) ($metadata['digest_weekly_day'] ?? 1),
                'preferred_channels' => $this->normalizeChannels(
                    $metadata['preferred_channels'] ?? null,
                    NotificationCatalog::supportedChannels(),
                    config('notification-center.defaults.preferred_channels', [])
                ),
                'fallback_channels' => $this->normalizeChannels(
                    $metadata['fallback_channels'] ?? null,
                    NotificationCatalog::supportedChannels(),
                    config('notification-center.defaults.fallback_channels', [])
                ),
                'fallback_strategy' => (string) ($metadata['fallback_strategy'] ?? config('notification-center.defaults.fallback_strategy', 'next_available')),
                'urgent_override' => (bool) ($metadata['urgent_override'] ?? true),
            ],
            'families' => $families,
            'triggers' => $triggers,
            'grouped_triggers' => $groupedTriggers,
            'destinations' => $this->destinationState($user),
            'options' => [
                'channels' => collect(NotificationChannel::userSelectable())
                    ->map(fn (NotificationChannel $channel): array => [
                        'value' => $channel->value,
                        'label' => $channel->label(),
                    ])
                    ->values()
                    ->all(),
                'cadences' => [
                    NotificationCadence::Instant->value => __('notifications.options.cadence.instant'),
                    NotificationCadence::Daily->value => __('notifications.options.cadence.daily'),
                    NotificationCadence::Weekly->value => __('notifications.options.cadence.weekly'),
                    NotificationCadence::Off->value => __('notifications.options.cadence.off'),
                ],
                'fallback_strategies' => [
                    'next_available' => __('notifications.options.fallback.next_available'),
                    'in_app_only' => __('notifications.options.fallback.in_app_only'),
                    'skip' => __('notifications.options.fallback.skip'),
                ],
                'weekly_days' => [
                    1 => __('notifications.options.weekdays.monday'),
                    2 => __('notifications.options.weekdays.tuesday'),
                    3 => __('notifications.options.weekdays.wednesday'),
                    4 => __('notifications.options.weekdays.thursday'),
                    5 => __('notifications.options.weekdays.friday'),
                    6 => __('notifications.options.weekdays.saturday'),
                    7 => __('notifications.options.weekdays.sunday'),
                ],
                'locales' => config('app.supported_locales', []),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function save(User $user, array $payload): array
    {
        $this->ensureUserConfiguration($user);

        /** @var CommunicationPreference $setting */
        $setting = $user->notificationSetting()->firstOrFail();

        /** @var array<string, mixed> $settingsInput */
        $settingsInput = Arr::get($payload, 'settings', []);
        $setting->forceFill([
            'locale' => $this->normalizeLocale((string) Arr::get($settingsInput, 'locale', app()->getLocale())),
            'timezone' => $this->normalizeTimezone((string) Arr::get($settingsInput, 'timezone', $this->userStringAttribute($user, 'timezone') ?: config('app.timezone'))),
            'quiet_hours_start' => $this->normalizeTimeValue(Arr::get($settingsInput, 'quiet_hours_start')),
            'quiet_hours_end' => $this->normalizeTimeValue(Arr::get($settingsInput, 'quiet_hours_end')),
        ]);

        $saveFallbackChannels = $this->normalizeChannels(
            Arr::get($settingsInput, 'fallback_channels'),
            NotificationCatalog::supportedChannels(),
            config('notification-center.defaults.fallback_channels', [])
        );

        $currentMetadata = is_array($setting->metadata) ? $setting->metadata : [];
        $setting->metadata = array_merge($currentMetadata, [
            'digest_delivery_time' => $this->normalizeTimeValue(Arr::get($settingsInput, 'digest_delivery_time')) ?: config('notification-center.defaults.digest_delivery_time'),
            'digest_weekly_day' => $this->normalizeWeeklyDay((int) Arr::get($settingsInput, 'digest_weekly_day', 1)),
            'preferred_channels' => $this->normalizeChannels(
                Arr::get($settingsInput, 'preferred_channels'),
                NotificationCatalog::supportedChannels(),
                config('notification-center.defaults.preferred_channels', [])
            ),
            'fallback_channels' => $saveFallbackChannels,
            'fallback_strategy' => $this->normalizeFallbackStrategy((string) Arr::get($settingsInput, 'fallback_strategy', 'next_available')),
            'urgent_override' => (bool) Arr::get($settingsInput, 'urgent_override', true),
        ]);

        $setting->save();

        /** @var array<string, mixed> $familyInput */
        $familyInput = Arr::get($payload, 'families', []);
        foreach (NotificationCatalog::families() as $familyKey => $definition) {
            /** @var array<string, mixed> $state */
            $state = Arr::get($familyInput, $familyKey, []);
            $enabled = (bool) Arr::get($state, 'enabled', true);

            CommunicationPreference::query()->updateOrCreate(
                [
                    'recipient_type' => $user->getMorphClass(),
                    'recipient_id' => $user->getKey(),
                    'scope_type' => NotificationRuleScope::Family->value,
                    'scope_key' => $familyKey,
                ],
                [
                    'enabled_at' => $enabled ? now() : null,
                    'metadata' => array_merge(
                        $this->currentScopeMeta($user, NotificationRuleScope::Family->value, $familyKey),
                        [
                            'cadence' => $this->normalizeCadence((string) Arr::get($state, 'cadence', $definition['default_cadence']->value))->value,
                            'channels' => $this->normalizeChannels(
                                Arr::get($state, 'channels'),
                                $definition['allowed_channels'],
                                $definition['default_channels']
                            ),
                            'fallback_channels' => $saveFallbackChannels,
                            'urgent_override' => null,
                            'inherits_family' => false,
                        ]
                    ),
                ]
            );
        }

        /** @var array<string, mixed> $triggerInput */
        $triggerInput = Arr::get($payload, 'triggers', []);
        foreach (NotificationCatalog::triggers() as $triggerKey => $definition) {
            /** @var array<string, mixed> $state */
            $state = Arr::get($triggerInput, $triggerKey, []);
            $inheritsFamily = Arr::has($state, 'inherits_family')
                ? (bool) Arr::get($state, 'inherits_family')
                : ! (Arr::has($state, 'cadence') || Arr::has($state, 'channels') || Arr::has($state, 'urgent_override'));
            $enabled = (bool) Arr::get($state, 'enabled', true);

            CommunicationPreference::query()->updateOrCreate(
                [
                    'recipient_type' => $user->getMorphClass(),
                    'recipient_id' => $user->getKey(),
                    'scope_type' => NotificationRuleScope::Trigger->value,
                    'scope_key' => $triggerKey,
                ],
                [
                    'enabled_at' => $enabled ? now() : null,
                    'metadata' => array_merge(
                        $this->currentScopeMeta($user, NotificationRuleScope::Trigger->value, $triggerKey),
                        [
                            'cadence' => $this->normalizeCadence((string) Arr::get($state, 'cadence', $definition['default_cadence']->value))->value,
                            'channels' => $this->normalizeChannels(
                                Arr::get($state, 'channels'),
                                $definition['allowed_channels'],
                                $definition['default_channels']
                            ),
                            'fallback_channels' => $saveFallbackChannels,
                            'urgent_override' => ! $inheritsFamily && Arr::has($state, 'urgent_override')
                                ? (bool) Arr::get($state, 'urgent_override')
                                : null,
                            'inherits_family' => $inheritsFamily,
                        ]
                    ),
                ]
            );
        }

        $this->syncSystemDestinations($user);

        return $this->stateFor($user);
    }

    public function resolvePolicy(User $user, NotificationTrigger $trigger): ResolvedNotificationPolicy
    {
        $this->ensureUserConfiguration($user);

        /** @var CommunicationPreference $setting */
        $setting = $user->notificationSetting()->firstOrFail();
        $triggerDefinition = NotificationCatalog::triggerDefinition($trigger);
        NotificationCatalog::familyDefinition($triggerDefinition['family']);

        /** @var CommunicationPreference $familyRule */
        $familyRule = $this->scopePreferencesFor($user)
            ->where('scope_type', NotificationRuleScope::Family->value)
            ->where('scope_key', $triggerDefinition['family']->value)
            ->firstOrFail();
        /** @var CommunicationPreference $triggerRule */
        $triggerRule = $this->scopePreferencesFor($user)
            ->where('scope_type', NotificationRuleScope::Trigger->value)
            ->where('scope_key', $trigger->value)
            ->firstOrFail();

        $familyMeta = $this->metaArray($familyRule);
        $triggerMeta = $this->metaArray($triggerRule);
        $inheritsFamily = (bool) ($triggerMeta['inherits_family'] ?? true);

        $cadenceValue = $inheritsFamily
            ? ($familyMeta['cadence'] ?? $triggerDefinition['default_cadence']->value)
            : ($triggerMeta['cadence'] ?? $familyMeta['cadence'] ?? $triggerDefinition['default_cadence']->value);

        $cadence = NotificationCadence::tryFrom($cadenceValue) ?? $triggerDefinition['default_cadence'];

        $channels = $inheritsFamily
            ? $this->normalizeChannels(
                $familyMeta['channels'] ?? null,
                $triggerDefinition['allowed_channels'],
                $triggerDefinition['default_channels'],
            )
            : $this->normalizeChannels(
                $triggerMeta['channels'] ?? null,
                $triggerDefinition['allowed_channels'],
                $triggerDefinition['default_channels'],
            );

        $metadata = is_array($setting->metadata) ? $setting->metadata : [];

        $preferredChannels = $this->normalizeChannels(
            $metadata['preferred_channels'] ?? null,
            NotificationCatalog::supportedChannels(),
            config('notification-center.defaults.preferred_channels', [])
        );
        $fallbackChannels = $this->normalizeChannels(
            $metadata['fallback_channels'] ?? null,
            NotificationCatalog::supportedChannels(),
            config('notification-center.defaults.fallback_channels', [])
        );

        return new ResolvedNotificationPolicy(
            family: $triggerDefinition['family'],
            trigger: $trigger,
            enabled: $familyRule->enabled_at !== null && $triggerRule->enabled_at !== null && $cadence !== NotificationCadence::Off,
            cadence: $cadence,
            channels: $channels,
            preferredChannels: $preferredChannels,
            fallbackChannels: $fallbackChannels,
            fallbackStrategy: $this->normalizeFallbackStrategy((string) ($metadata['fallback_strategy'] ?? 'next_available')),
            urgentOverride: $triggerMeta['urgent_override'] ?? $familyMeta['urgent_override'] ?? (bool) ($metadata['urgent_override'] ?? true),
            quietHoursStart: $this->normalizeTimeValue($setting->quiet_hours_start),
            quietHoursEnd: $this->normalizeTimeValue($setting->quiet_hours_end),
            digestDeliveryTime: $this->normalizeTimeValue($metadata['digest_delivery_time'] ?? null),
            digestWeeklyDay: $this->normalizeWeeklyDay((int) ($metadata['digest_weekly_day'] ?? 1)),
            locale: $this->normalizeLocale((string) ($setting->locale ?: app()->getLocale())),
            timezone: $this->normalizeTimezone((string) ($setting->timezone ?: ($this->userStringAttribute($user, 'timezone') ?: config('app.timezone')))),
        );
    }

    public function syncSystemDestinations(User $user): void
    {
        $email = $this->userStringAttribute($user, 'email');
        $phone = $this->userStringAttribute($user, 'phone');
        $emailVerifiedAt = $this->userAttribute($user, 'email_verified_at');
        $phoneVerifiedAt = $this->userAttribute($user, 'phone_verified_at');

        $recipientKeys = [
            'recipient_type' => $user->getMorphClass(),
            'recipient_id' => $user->id,
        ];

        if (filled($email)) {
            CommunicationDestination::query()->updateOrCreate(
                [
                    ...$recipientKeys,
                    'channel' => NotificationChannel::Email->value,
                    'address' => $email,
                ],
                [
                    'external_id' => null,
                    'status' => $emailVerifiedAt !== null
                        ? 'active'
                        : 'inactive',
                    'is_primary' => true,
                    'verified_at' => $emailVerifiedAt,
                    'metadata' => ['source' => 'account_email'],
                ]
            );

            CommunicationDestination::query()
                ->where($recipientKeys)
                ->where('channel', NotificationChannel::Email->value)
                ->where('address', '!=', $email)
                ->delete();
        } else {
            CommunicationDestination::query()
                ->where($recipientKeys)
                ->where('channel', NotificationChannel::Email->value)
                ->delete();
        }

        if (filled($phone) && $phoneVerifiedAt !== null) {
            CommunicationDestination::query()->updateOrCreate(
                [
                    ...$recipientKeys,
                    'channel' => NotificationChannel::Whatsapp->value,
                    'address' => $phone,
                ],
                [
                    'external_id' => null,
                    'status' => 'active',
                    'is_primary' => true,
                    'verified_at' => $phoneVerifiedAt,
                    'metadata' => ['source' => 'account_phone'],
                ]
            );

            CommunicationDestination::query()
                ->where($recipientKeys)
                ->where('channel', NotificationChannel::Whatsapp->value)
                ->where('address', '!=', $phone)
                ->delete();
        } else {
            CommunicationDestination::query()
                ->where($recipientKeys)
                ->where('channel', NotificationChannel::Whatsapp->value)
                ->delete();
        }
    }

    public function syncProfileSettings(User $user): void
    {
        $this->ensureUserConfiguration($user);

        $timezone = $this->normalizeTimezone(
            $this->userStringAttribute($user, 'timezone') ?: (config('app.timezone') ?: 'UTC')
        );

        CommunicationPreference::query()->updateOrCreate(
            [
                'recipient_type' => $user->getMorphClass(),
                'recipient_id' => $user->getKey(),
                'channel' => null,
                'category' => null,
            ],
            ['timezone' => $timezone],
        );

        $this->syncSystemDestinations($user);
    }

    /**
     * @return Collection<int, CommunicationDestination>
     */
    public function destinationsFor(User $user, NotificationChannel $channel): Collection
    {
        $this->syncSystemDestinations($user);

        return $user->notificationDestinations()
            ->where('channel', $channel->value)
            ->where('status', 'active')
            ->orderByDesc('is_primary')
            ->orderByDesc('last_seen_at')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    protected function destinationState(User $user): array
    {
        $email = $this->userStringAttribute($user, 'email');
        $phone = $this->userStringAttribute($user, 'phone');
        $emailVerifiedAt = $this->userAttribute($user, 'email_verified_at');
        $phoneVerifiedAt = $this->userAttribute($user, 'phone_verified_at');

        /** @var Collection<int, CommunicationDestination> $destinations */
        $destinations = $user->notificationDestinations()->get();

        return [
            'email' => [
                'available' => filled($email),
                'verified' => $emailVerifiedAt !== null,
                'address' => $email,
            ],
            'whatsapp' => [
                'available' => filled($phone),
                'verified' => $phoneVerifiedAt !== null,
                'address' => $phone,
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
                    'verified_at' => $this->dateTimeToIso($destination->verified_at),
                ])
                ->all(),
        ];
    }

    /**
     * @param  list<string>  $allowed
     * @param  list<string>  $fallback
     * @return list<string>
     */
    protected function normalizeChannels(mixed $value, array $allowed, array $fallback): array
    {
        $channels = collect(is_array($value) ? $value : $fallback)
            ->map(static fn (mixed $channel): string => (string) $channel)
            ->filter(static fn (string $channel): bool => in_array($channel, $allowed, true))
            ->unique()
            ->values()
            ->all();

        return $channels === [] ? array_values(array_intersect($fallback, $allowed)) : $channels;
    }

    protected function normalizeCadence(string $value): NotificationCadence
    {
        return NotificationCadence::tryFrom($value) ?? NotificationCadence::Instant;
    }

    protected function normalizeFallbackStrategy(string $value): string
    {
        return in_array($value, ['next_available', 'in_app_only', 'skip'], true) ? $value : 'next_available';
    }

    protected function normalizeLocale(string $value): string
    {
        $supportedLocales = array_keys(config('app.supported_locales', []));

        return in_array($value, $supportedLocales, true)
            ? $value
            : config('app.locale');
    }

    protected function normalizeTimezone(string $value): string
    {
        return in_array($value, \DateTimeZone::listIdentifiers(), true)
            ? $value
            : (config('app.timezone') ?: 'UTC');
    }

    protected function normalizeWeeklyDay(int $value): int
    {
        return max(1, min(7, $value));
    }

    protected function normalizeTimeValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^\d{2}:\d{2}$/', $trimmed) === 1) {
            return $trimmed.':00';
        }

        return preg_match('/^\d{2}:\d{2}:\d{2}$/', $trimmed) === 1 ? $trimmed : null;
    }

    protected function userStringAttribute(User $user, string $key): ?string
    {
        $value = $this->userAttribute($user, $key);

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    protected function userAttribute(User $user, string $key): mixed
    {
        $attributes = $user->getAttributes();

        if (array_key_exists($key, $attributes)) {
            return $attributes[$key];
        }

        /** @var User|null $freshUser */
        $freshUser = User::query()
            ->whereKey($user->getKey())
            ->select(['id', $key])
            ->first();

        return $freshUser?->getAttributes()[$key] ?? null;
    }

    /**
     * @return CommunicationPreference|Builder
     */
    private function scopePreferencesFor(User $user)
    {
        return CommunicationPreference::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->getKey())
            ->whereNotNull('scope_type');
    }

    private function dateTimeToIso(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->toIso8601String();
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
        $meta = $preference->metadata;

        return is_array($meta) ? $meta : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentScopeMeta(User $user, string $scopeType, string $scopeKey): array
    {
        $existing = CommunicationPreference::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->getKey())
            ->where('scope_type', $scopeType)
            ->where('scope_key', $scopeKey)
            ->first();

        if ($existing === null) {
            return [];
        }

        $meta = $existing->metadata;

        return is_array($meta) ? $meta : [];
    }
}
