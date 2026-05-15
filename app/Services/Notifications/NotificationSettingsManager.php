<?php

namespace App\Services\Notifications;

use App\Enums\NotificationCadence;
use App\Enums\NotificationChannel;
use App\Enums\NotificationDestinationStatus;
use App\Enums\NotificationPriority;
use App\Enums\NotificationRuleScope;
use App\Enums\NotificationTrigger;
use App\Models\NotificationDestination;
use App\Models\NotificationRule;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Support\Notifications\NotificationCatalog;
use App\Support\Notifications\ResolvedNotificationPolicy;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class NotificationSettingsManager
{
    public function ensureUserConfiguration(User $user): void
    {
        NotificationSetting::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'locale' => app()->getLocale(),
                'timezone' => $this->userStringAttribute($user, 'timezone') ?: config('app.timezone'),
                'digest_delivery_time' => config('notification-center.defaults.digest_delivery_time'),
                'digest_weekly_day' => (int) config('notification-center.defaults.digest_weekly_day', 1),
                'preferred_channels' => config('notification-center.defaults.preferred_channels', []),
                'fallback_channels' => config('notification-center.defaults.fallback_channels', []),
                'fallback_strategy' => (string) config('notification-center.defaults.fallback_strategy', 'next_available'),
                'urgent_override' => true,
            ]
        );

        $existingRules = NotificationRule::query()
            ->where('user_id', $user->id)
            ->get(['scope_type', 'scope_key'])
            ->groupBy(static fn (NotificationRule $rule): string => $rule->scope_type instanceof NotificationRuleScope
                ? $rule->scope_type->value
                : (string) $rule->scope_type)
            ->map(static fn (Collection $rules): array => $rules
                ->pluck('scope_key')
                ->map(static fn (mixed $scopeKey): string => (string) $scopeKey)
                ->all());

        $rulePrototype = new NotificationRule;
        $missingRules = [];
        $timestamp = now();

        foreach (NotificationCatalog::families() as $familyKey => $definition) {
            if (in_array($familyKey, $existingRules->get(NotificationRuleScope::Family->value, []), true)) {
                continue;
            }

            $missingRules[] = [
                'id' => $rulePrototype->newUniqueId(),
                'user_id' => $user->id,
                'scope_type' => NotificationRuleScope::Family->value,
                'scope_key' => $familyKey,
                'enabled' => true,
                'cadence' => $definition['default_cadence']->value,
                'channels' => json_encode($definition['default_channels'], JSON_THROW_ON_ERROR),
                'fallback_channels' => json_encode($definition['default_channels'], JSON_THROW_ON_ERROR),
                'urgent_override' => null,
                'meta' => json_encode(['inherits_family' => false], JSON_THROW_ON_ERROR),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        foreach (NotificationCatalog::triggers() as $triggerKey => $definition) {
            if (in_array($triggerKey, $existingRules->get(NotificationRuleScope::Trigger->value, []), true)) {
                continue;
            }

            $missingRules[] = [
                'id' => $rulePrototype->newUniqueId(),
                'user_id' => $user->id,
                'scope_type' => NotificationRuleScope::Trigger->value,
                'scope_key' => $triggerKey,
                'enabled' => true,
                'cadence' => $definition['default_cadence']->value,
                'channels' => json_encode($definition['default_channels'], JSON_THROW_ON_ERROR),
                'fallback_channels' => json_encode($definition['default_channels'], JSON_THROW_ON_ERROR),
                'urgent_override' => null,
                'meta' => json_encode(['inherits_family' => true], JSON_THROW_ON_ERROR),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        if ($missingRules !== []) {
            NotificationRule::query()->insertOrIgnore($missingRules);
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

        /** @var NotificationSetting $setting */
        $setting = $user->notificationSetting()->firstOrFail();
        /** @var Collection<int, NotificationRule> $rules */
        $rules = $user->notificationRules()->get();

        $familyRuleMap = $rules
            ->where('scope_type', NotificationRuleScope::Family)
            ->keyBy('scope_key');
        $triggerRuleMap = $rules
            ->where('scope_type', NotificationRuleScope::Trigger)
            ->keyBy('scope_key');

        $families = [];
        foreach (NotificationCatalog::families() as $familyKey => $definition) {
            /** @var NotificationRule|null $rule */
            $rule = $familyRuleMap->get($familyKey);
            $familyCadence = $rule?->cadence instanceof NotificationCadence ? $rule->cadence->value : $definition['default_cadence']->value;
            $familyChannels = $this->normalizeChannels(
                $rule instanceof NotificationRule ? $rule->channels : null,
                $definition['allowed_channels'],
                $definition['default_channels'],
            );

            $families[$familyKey] = [
                'scope_key' => $familyKey,
                'enabled' => $rule instanceof NotificationRule ? $rule->enabled : true,
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
            /** @var NotificationRule|null $rule */
            $rule = $triggerRuleMap->get($triggerKey);
            $familyKey = $definition['family']->value;
            /** @var NotificationRule|null $familyRule */
            $familyRule = $familyRuleMap->get($familyKey);
            $inheritsFamily = (bool) data_get($rule instanceof NotificationRule ? $rule->meta : [], 'inherits_family', true);
            $resolvedCadence = $inheritsFamily
                ? ($familyRule instanceof NotificationRule && $familyRule->cadence instanceof NotificationCadence
                    ? $familyRule->cadence->value
                    : $definition['default_cadence']->value)
                : ($rule instanceof NotificationRule && $rule->cadence instanceof NotificationCadence
                    ? $rule->cadence->value
                    : $definition['default_cadence']->value);
            $resolvedChannels = $inheritsFamily
                ? $this->normalizeChannels(
                    $familyRule instanceof NotificationRule ? $familyRule->channels : null,
                    $definition['allowed_channels'],
                    $definition['default_channels'],
                )
                : $this->normalizeChannels(
                    $rule instanceof NotificationRule ? $rule->channels : null,
                    $definition['allowed_channels'],
                    $definition['default_channels'],
                );

            $triggerState = [
                'scope_key' => $triggerKey,
                'family' => $familyKey,
                'enabled' => (bool) ($rule instanceof NotificationRule ? $rule->enabled : true),
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
                'urgent_override' => $rule instanceof NotificationRule ? $rule->urgent_override : null,
            ];

            $triggers[$triggerKey] = $triggerState;
            $groupedTriggers[$familyKey] ??= [];
            $groupedTriggers[$familyKey][] = $triggerState;
        }

        return [
            'settings' => [
                'locale' => (string) ($setting->locale ?: app()->getLocale()),
                'timezone' => (string) ($setting->timezone ?: ($this->userStringAttribute($user, 'timezone') ?: config('app.timezone'))),
                'quiet_hours_start' => (string) ($setting->quiet_hours_start ?? ''),
                'quiet_hours_end' => (string) ($setting->quiet_hours_end ?? ''),
                'digest_delivery_time' => (string) ($setting->digest_delivery_time ?: config('notification-center.defaults.digest_delivery_time')),
                'digest_weekly_day' => (int) ($setting->digest_weekly_day ?: 1),
                'preferred_channels' => $this->normalizeChannels(
                    $setting->preferred_channels,
                    NotificationCatalog::supportedChannels(),
                    config('notification-center.defaults.preferred_channels', [])
                ),
                'fallback_channels' => $this->normalizeChannels(
                    $setting->fallback_channels,
                    NotificationCatalog::supportedChannels(),
                    config('notification-center.defaults.fallback_channels', [])
                ),
                'fallback_strategy' => (string) ($setting->fallback_strategy ?: config('notification-center.defaults.fallback_strategy', 'next_available')),
                'urgent_override' => (bool) $setting->urgent_override,
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

        /** @var NotificationSetting $setting */
        $setting = $user->notificationSetting()->firstOrFail();

        /** @var array<string, mixed> $settingsInput */
        $settingsInput = Arr::get($payload, 'settings', []);
        $setting->forceFill([
            'locale' => $this->normalizeLocale((string) Arr::get($settingsInput, 'locale', app()->getLocale())),
            'timezone' => $this->normalizeTimezone((string) Arr::get($settingsInput, 'timezone', $this->userStringAttribute($user, 'timezone') ?: config('app.timezone'))),
            'quiet_hours_start' => $this->normalizeTimeValue(Arr::get($settingsInput, 'quiet_hours_start')),
            'quiet_hours_end' => $this->normalizeTimeValue(Arr::get($settingsInput, 'quiet_hours_end')),
            'digest_delivery_time' => $this->normalizeTimeValue(Arr::get($settingsInput, 'digest_delivery_time')) ?: config('notification-center.defaults.digest_delivery_time'),
            'digest_weekly_day' => $this->normalizeWeeklyDay((int) Arr::get($settingsInput, 'digest_weekly_day', 1)),
            'preferred_channels' => $this->normalizeChannels(
                Arr::get($settingsInput, 'preferred_channels'),
                NotificationCatalog::supportedChannels(),
                config('notification-center.defaults.preferred_channels', [])
            ),
            'fallback_channels' => $this->normalizeChannels(
                Arr::get($settingsInput, 'fallback_channels'),
                NotificationCatalog::supportedChannels(),
                config('notification-center.defaults.fallback_channels', [])
            ),
            'fallback_strategy' => $this->normalizeFallbackStrategy((string) Arr::get($settingsInput, 'fallback_strategy', 'next_available')),
            'urgent_override' => (bool) Arr::get($settingsInput, 'urgent_override', true),
        ])->save();

        /** @var array<string, mixed> $familyInput */
        $familyInput = Arr::get($payload, 'families', []);
        foreach (NotificationCatalog::families() as $familyKey => $definition) {
            /** @var array<string, mixed> $state */
            $state = Arr::get($familyInput, $familyKey, []);
            NotificationRule::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'scope_type' => NotificationRuleScope::Family->value,
                    'scope_key' => $familyKey,
                ],
                [
                    'enabled' => (bool) Arr::get($state, 'enabled', true),
                    'cadence' => $this->normalizeCadence((string) Arr::get($state, 'cadence', $definition['default_cadence']->value))->value,
                    'channels' => $this->normalizeChannels(
                        Arr::get($state, 'channels'),
                        $definition['allowed_channels'],
                        $definition['default_channels']
                    ),
                    'fallback_channels' => $setting->fallback_channels,
                    'urgent_override' => null,
                    'meta' => ['inherits_family' => false],
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
            NotificationRule::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'scope_type' => NotificationRuleScope::Trigger->value,
                    'scope_key' => $triggerKey,
                ],
                [
                    'enabled' => (bool) Arr::get($state, 'enabled', true),
                    'cadence' => $this->normalizeCadence((string) Arr::get($state, 'cadence', $definition['default_cadence']->value))->value,
                    'channels' => $this->normalizeChannels(
                        Arr::get($state, 'channels'),
                        $definition['allowed_channels'],
                        $definition['default_channels']
                    ),
                    'fallback_channels' => $setting->fallback_channels,
                    'urgent_override' => ! $inheritsFamily && Arr::has($state, 'urgent_override')
                        ? (bool) Arr::get($state, 'urgent_override')
                        : null,
                    'meta' => ['inherits_family' => $inheritsFamily],
                ]
            );
        }

        $this->syncSystemDestinations($user);

        return $this->stateFor($user);
    }

    public function resolvePolicy(User $user, NotificationTrigger $trigger): ResolvedNotificationPolicy
    {
        $this->ensureUserConfiguration($user);

        /** @var NotificationSetting $setting */
        $setting = $user->notificationSetting()->firstOrFail();
        $triggerDefinition = NotificationCatalog::triggerDefinition($trigger);
        NotificationCatalog::familyDefinition($triggerDefinition['family']);

        /** @var NotificationRule $familyRule */
        $familyRule = $user->notificationRules()
            ->where('scope_type', NotificationRuleScope::Family->value)
            ->where('scope_key', $triggerDefinition['family']->value)
            ->firstOrFail();
        /** @var NotificationRule $triggerRule */
        $triggerRule = $user->notificationRules()
            ->where('scope_type', NotificationRuleScope::Trigger->value)
            ->where('scope_key', $trigger->value)
            ->firstOrFail();
        $inheritsFamily = (bool) data_get($triggerRule->meta, 'inherits_family', true);

        $cadence = $inheritsFamily
            ? ($familyRule->cadence instanceof NotificationCadence ? $familyRule->cadence : $triggerDefinition['default_cadence'])
            : ($triggerRule->cadence instanceof NotificationCadence
                ? $triggerRule->cadence
                : ($familyRule->cadence instanceof NotificationCadence ? $familyRule->cadence : $triggerDefinition['default_cadence']));

        $channels = $inheritsFamily
            ? $this->normalizeChannels(
                $familyRule->channels,
                $triggerDefinition['allowed_channels'],
                $triggerDefinition['default_channels'],
            )
            : $this->normalizeChannels(
                $triggerRule->channels,
                $triggerDefinition['allowed_channels'],
                $triggerDefinition['default_channels'],
            );

        $preferredChannels = $this->normalizeChannels(
            $setting->preferred_channels,
            NotificationCatalog::supportedChannels(),
            config('notification-center.defaults.preferred_channels', [])
        );
        $fallbackChannels = $this->normalizeChannels(
            $setting->fallback_channels,
            NotificationCatalog::supportedChannels(),
            config('notification-center.defaults.fallback_channels', [])
        );

        return new ResolvedNotificationPolicy(
            family: $triggerDefinition['family'],
            trigger: $trigger,
            enabled: $familyRule->enabled && $triggerRule->enabled && $cadence !== NotificationCadence::Off,
            cadence: $cadence,
            channels: $channels,
            preferredChannels: $preferredChannels,
            fallbackChannels: $fallbackChannels,
            fallbackStrategy: $this->normalizeFallbackStrategy((string) ($setting->fallback_strategy ?: 'next_available')),
            urgentOverride: $triggerRule->urgent_override ?? $familyRule->urgent_override ?? (bool) $setting->urgent_override,
            quietHoursStart: $this->normalizeTimeValue($setting->quiet_hours_start),
            quietHoursEnd: $this->normalizeTimeValue($setting->quiet_hours_end),
            digestDeliveryTime: $this->normalizeTimeValue($setting->digest_delivery_time),
            digestWeeklyDay: $this->normalizeWeeklyDay((int) ($setting->digest_weekly_day ?: 1)),
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

        if (filled($email)) {
            NotificationDestination::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'channel' => NotificationChannel::Email->value,
                    'address' => $email,
                ],
                [
                    'external_id' => null,
                    'status' => $emailVerifiedAt !== null
                        ? NotificationDestinationStatus::Active->value
                        : NotificationDestinationStatus::Inactive->value,
                    'is_primary' => true,
                    'verified_at' => $emailVerifiedAt,
                    'meta' => ['source' => 'account_email'],
                ]
            );

            NotificationDestination::query()
                ->where('user_id', $user->id)
                ->where('channel', NotificationChannel::Email->value)
                ->where('address', '!=', $email)
                ->delete();
        } else {
            NotificationDestination::query()
                ->where('user_id', $user->id)
                ->where('channel', NotificationChannel::Email->value)
                ->delete();
        }

        if (filled($phone) && $phoneVerifiedAt !== null) {
            NotificationDestination::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'channel' => NotificationChannel::Whatsapp->value,
                    'address' => $phone,
                ],
                [
                    'external_id' => null,
                    'status' => NotificationDestinationStatus::Active->value,
                    'is_primary' => true,
                    'verified_at' => $phoneVerifiedAt,
                    'meta' => ['source' => 'account_phone'],
                ]
            );

            NotificationDestination::query()
                ->where('user_id', $user->id)
                ->where('channel', NotificationChannel::Whatsapp->value)
                ->where('address', '!=', $phone)
                ->delete();
        } else {
            NotificationDestination::query()
                ->where('user_id', $user->id)
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

        $user->notificationSetting()->updateOrCreate(
            ['user_id' => $user->id],
            ['timezone' => $timezone],
        );

        $this->syncSystemDestinations($user);
    }

    /**
     * @return Collection<int, NotificationDestination>
     */
    public function destinationsFor(User $user, NotificationChannel $channel): Collection
    {
        $this->syncSystemDestinations($user);

        return $user->notificationDestinations()
            ->where('channel', $channel->value)
            ->where('status', NotificationDestinationStatus::Active->value)
            ->orderByDesc('is_primary')
            ->orderByDesc('verified_at')
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

        /** @var Collection<int, NotificationDestination> $destinations */
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
                ->map(function (NotificationDestination $destination): array {
                    $meta = is_array($destination->meta) ? $destination->meta : [];

                    return [
                        'id' => $destination->id,
                        'installation_id' => $destination->address,
                        'device_label' => (string) Arr::get($meta, 'device_label', __('notifications.destinations.unknown_device')),
                        'platform' => (string) Arr::get($meta, 'platform', 'unknown'),
                        'last_seen_at' => Arr::get($meta, 'last_seen_at'),
                        'verified_at' => $destination->verified_at instanceof \DateTimeInterface
                            ? $destination->verified_at->toIso8601String()
                            : null,
                    ];
                })
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
}
