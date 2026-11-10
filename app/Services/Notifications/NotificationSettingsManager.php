<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use AIArmada\Communications\Models\CommunicationDestination;
use AIArmada\Communications\Models\CommunicationPreference;
use App\Enums\NotificationCadence;
use App\Enums\NotificationChannel;
use App\Enums\NotificationFamily;
use App\Enums\NotificationRuleScope;
use App\Enums\NotificationTrigger;
use App\Models\User;
use App\Support\Communications\AccountCommunications;
use App\Support\Notifications\NotificationCatalog;
use App\Support\Notifications\ResolvedNotificationPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class NotificationSettingsManager
{
    public function __construct(
        private readonly AccountCommunications $communications,
    ) {}

    public function ensureUserConfiguration(User $user): void
    {
        $baseMatch = [
            'recipient_type' => $user->getMorphClass(),
            'recipient_id' => $user->getKey(),
            'channel' => null,
            'category' => null,
        ];

        if (! $this->findPreference($baseMatch) instanceof CommunicationPreference) {
            $this->createPreference($baseMatch, [
                'locale' => app()->getLocale(),
                'timezone' => $this->userTimezone($user),
                'enabled_at' => now(),
                'metadata' => [
                    'channels' => $this->defaultChannels(),
                ],
            ]);
        }

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
                'recipient_type' => $user->getMorphClass(),
                'recipient_id' => $user->getKey(),
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
        $setting = $this->communications->basePreferenceQuery((string) $user->getKey())->firstOrFail();
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

            $families[$familyKey] = [
                'scope_key' => $familyKey,
                'enabled' => $rule !== null ? ($rule->enabled_at !== null) : true,
                'cadence' => (string) ($ruleMeta['cadence'] ?? $definition['default_cadence']->value),
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
                'timezone' => $this->userTimezone($user),
                'quiet_hours_start' => (string) ($setting->quiet_hours_start ?? ''),
                'quiet_hours_end' => (string) ($setting->quiet_hours_end ?? ''),
                'channels' => $this->normalizeChannels($metadata['channels'] ?? null),
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
                ],
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
        $setting = $this->communications->basePreferenceQuery((string) $user->getKey())->firstOrFail();

        /** @var array<string, mixed> $settingsInput */
        $settingsInput = Arr::get($payload, 'settings', []);

        $setting->forceFill([
            'locale' => (string) ($setting->locale ?: app()->getLocale()),
            'timezone' => $this->userTimezone($user),
            'quiet_hours_start' => $this->normalizeTimeValue(Arr::get($settingsInput, 'quiet_hours_start')),
            'quiet_hours_end' => $this->normalizeTimeValue(Arr::get($settingsInput, 'quiet_hours_end')),
        ]);
        $setting->metadata = [
            'channels' => $this->normalizeChannels(Arr::get($settingsInput, 'channels')),
        ];
        $setting->save();

        /** @var array<string, mixed> $familyInput */
        $familyInput = Arr::get($payload, 'families', []);
        foreach (NotificationCatalog::families() as $familyKey => $definition) {
            if (! array_key_exists($familyKey, $familyInput)) {
                continue;
            }

            /** @var array<string, mixed> $state */
            $state = Arr::get($familyInput, $familyKey, []);
            $enabled = (bool) Arr::get($state, 'enabled', true);

            $this->updateOrCreatePreference(
                [
                    'recipient_type' => $user->getMorphClass(),
                    'recipient_id' => $user->getKey(),
                    'scope_type' => NotificationRuleScope::Family->value,
                    'scope_key' => $familyKey,
                ],
                [
                    'enabled_at' => $enabled ? now() : null,
                    'metadata' => [
                        'cadence' => $this->normalizeCadence(
                            (string) Arr::get($state, 'cadence', $definition['default_cadence']->value),
                        )->value,
                    ],
                ],
            );
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

            $this->updateOrCreatePreference(
                [
                    'recipient_type' => $user->getMorphClass(),
                    'recipient_id' => $user->getKey(),
                    'scope_type' => NotificationRuleScope::Trigger->value,
                    'scope_key' => $triggerKey,
                ],
                [
                    'enabled_at' => $enabled ? now() : null,
                    'metadata' => [],
                ],
            );
        }

        $this->syncSystemDestinations($user);

        return $this->stateFor($user);
    }

    public function resolvePolicy(User $user, NotificationTrigger $trigger): ResolvedNotificationPolicy
    {
        $this->ensureUserConfiguration($user);

        /** @var CommunicationPreference $setting */
        $setting = $this->communications->basePreferenceQuery((string) $user->getKey())->firstOrFail();
        $triggerDefinition = NotificationCatalog::triggerDefinition($trigger);
        $familyDefinition = NotificationCatalog::familyDefinition($triggerDefinition['family']);

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
        $metadata = is_array($setting->metadata) ? $setting->metadata : [];

        $cadence = NotificationCadence::tryFrom((string) ($familyMeta['cadence'] ?? $familyDefinition['default_cadence']->value))
            ?? $familyDefinition['default_cadence'];

        return new ResolvedNotificationPolicy(
            family: $triggerDefinition['family'],
            trigger: $trigger,
            enabled: $familyRule->enabled_at !== null
                && $triggerRule->enabled_at !== null
                && $cadence !== NotificationCadence::Off,
            cadence: $cadence,
            channels: $this->normalizeChannels($metadata['channels'] ?? null),
            quietHoursStart: $this->normalizeTimeValue($setting->quiet_hours_start),
            quietHoursEnd: $this->normalizeTimeValue($setting->quiet_hours_end),
            digestDeliveryTime: $this->normalizeTimeValue(config('notification-center.defaults.digest_delivery_time')),
            digestWeeklyDay: (int) config('notification-center.defaults.digest_weekly_day', 1),
            locale: (string) ($setting->locale ?: app()->getLocale()),
            timezone: $this->userTimezone($user),
        );
    }

    public function syncSystemDestinations(User $user): void
    {
        $email = $this->userStringAttribute($user, 'email');
        $emailVerifiedAt = $this->userAttribute($user, 'email_verified_at');

        $recipientKeys = [
            'recipient_type' => $user->getMorphClass(),
            'recipient_id' => $user->id,
        ];

        if (filled($email)) {
            $current = $this->findDestination($user, NotificationChannel::Email->value, $email);

            if ($current instanceof CommunicationDestination) {
                $current->forceFill([
                    'external_id' => null,
                    'status' => $emailVerifiedAt !== null ? 'active' : 'inactive',
                    'is_primary' => true,
                    'verified_at' => $emailVerifiedAt,
                    'metadata' => ['source' => 'account_email'],
                ])->save();
                $keepId = $current->getKey();
            } else {
                $created = new CommunicationDestination;
                $created->forceFill([
                    ...$recipientKeys,
                    'channel' => NotificationChannel::Email->value,
                    'address' => $email,
                    'external_id' => null,
                    'status' => $emailVerifiedAt !== null ? 'active' : 'inactive',
                    'is_primary' => true,
                    'verified_at' => $emailVerifiedAt,
                    'metadata' => ['source' => 'account_email'],
                ]);
                $created->save();
                $keepId = $created->getKey();
            }

            CommunicationDestination::query()
                ->where($recipientKeys)
                ->where('channel', NotificationChannel::Email->value)
                ->where('id', '!=', $keepId)
                ->delete();
        } else {
            CommunicationDestination::query()
                ->where($recipientKeys)
                ->where('channel', NotificationChannel::Email->value)
                ->delete();
        }
    }

    public function syncProfileSettings(User $user): void
    {
        $this->ensureUserConfiguration($user);

        $timezone = $this->normalizeTimezone($this->userTimezone($user));

        $this->updateOrCreatePreference(
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

        return $this->communications->destinationsQuery((string) $user->getKey())
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
        $emailVerifiedAt = $this->userAttribute($user, 'email_verified_at');

        /** @var Collection<int, CommunicationDestination> $destinations */
        $destinations = $this->communications->destinationsQuery((string) $user->getKey())->get();

        return [
            'email' => [
                'available' => filled($email),
                'verified' => $emailVerifiedAt !== null,
                'address' => $email,
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
     * @return list<string>
     */
    protected function normalizeChannels(mixed $value): array
    {
        $allowed = NotificationCatalog::supportedChannels();
        $fallback = $this->defaultChannels();

        $channels = collect(is_array($value) ? $value : $fallback)
            ->map(static fn (mixed $channel): string => (string) $channel)
            ->filter(static fn (string $channel): bool => in_array($channel, $allowed, true))
            ->unique()
            ->values()
            ->all();

        if ($channels === []) {
            $channels = $fallback;
        }

        if (! in_array(NotificationChannel::InApp->value, $channels, true)) {
            array_unshift($channels, NotificationChannel::InApp->value);
        }

        return array_values(array_unique($channels));
    }

    /**
     * @return list<string>
     */
    protected function defaultChannels(): array
    {
        return [
            NotificationChannel::InApp->value,
            NotificationChannel::Email->value,
        ];
    }

    protected function normalizeCadence(string $value): NotificationCadence
    {
        return NotificationCadence::tryFrom($value) ?? NotificationCadence::Instant;
    }

    protected function normalizeTimezone(string $value): string
    {
        return in_array($value, \DateTimeZone::listIdentifiers(), true)
            ? $value
            : (config('app.timezone') ?: 'UTC');
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

    protected function userTimezone(User $user): string
    {
        $timezone = $this->userStringAttribute($user, 'timezone');

        return $timezone !== null ? $this->normalizeTimezone($timezone) : 'Asia/Kuala_Lumpur';
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
     * The package model guards recipient_type/scope_type from mass assignment,
     * so preference writes go through explicit forceFill.
     *
     * @param  array<string, mixed>  $match
     */
    private function findPreference(array $match): ?CommunicationPreference
    {
        $query = CommunicationPreference::query();

        foreach ($match as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        return $query->first();
    }

    /**
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $values
     */
    private function createPreference(array $match, array $values): CommunicationPreference
    {
        $preference = new CommunicationPreference;
        $preference->forceFill(array_merge($match, $values));
        $preference->save();

        return $preference;
    }

    /**
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $values
     */
    private function updateOrCreatePreference(array $match, array $values): CommunicationPreference
    {
        $preference = $this->findPreference($match);

        if (! $preference instanceof CommunicationPreference) {
            return $this->createPreference($match, $values);
        }

        $preference->update($values);

        return $preference;
    }

    /**
     * Addresses are encrypted at rest, so address matching decrypts in PHP.
     */
    private function findDestination(User $user, string $channel, string $address): ?CommunicationDestination
    {
        $match = CommunicationDestination::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->id)
            ->where('channel', $channel)
            ->get()
            ->first(fn (CommunicationDestination $destination): bool => $destination->address === $address);

        return $match instanceof CommunicationDestination ? $match : null;
    }
}
