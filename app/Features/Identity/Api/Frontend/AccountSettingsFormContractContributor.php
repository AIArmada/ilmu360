<?php

declare(strict_types=1);

namespace App\Features\Identity\Api\Frontend;

use App\Features\Identity\Api\AccountPreferences;
use App\Models\User;
use App\Platform\Api\Frontend\Contracts\FrontendFormContractContributor;
use App\Platform\Api\Frontend\FrontendFormContractKey;
use App\Platform\Api\Frontend\Support\FrontendFormContractField;
use App\Platform\Api\Frontend\Support\FrontendFormMcpTokens;
use InvalidArgumentException;

final readonly class AccountSettingsFormContractContributor implements FrontendFormContractContributor
{
    public function __construct(
        private FrontendFormMcpTokens $mcpTokens,
        private AccountPreferences $accountPreferences,
    ) {}

    public function supports(string $key): bool
    {
        return $key === FrontendFormContractKey::ACCOUNT_SETTINGS;
    }

    public function manifest(?User $user): ?array
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function contract(string $key, array $context): array
    {
        $user = $context['user'] ?? null;

        if (! $user instanceof User) {
            throw new InvalidArgumentException('The account settings form contract requires an authenticated account.');
        }

        $mcpServers = $this->mcpTokens->availableServers($user);
        $preferences = $this->accountPreferences->read((string) $user->getKey());

        return [
            'flow' => 'account_settings',
            'method' => 'PUT',
            'endpoint' => route('api.client.account-settings.update'),
            'show_endpoint' => route('api.client.account-settings.show'),
            'auth_required' => true,
            'defaults' => [
                'name' => $user->name,
                'email' => (string) ($user->email ?? ''),
                'phone' => (string) ($user->phone ?? ''),
                'timezone' => (string) ($user->timezone ?? ''),
                'daily_prayer_institution_ids' => $preferences->dailyPrayerInstitutionIds,
                'friday_prayer_institution_ids' => $preferences->fridayPrayerInstitutionIds,
            ],
            'fields' => [
                FrontendFormContractField::make('name', 'string', required: true, maxLength: 255),
                FrontendFormContractField::make('email', 'email', required: false, maxLength: 255),
                FrontendFormContractField::make('phone', 'string', required: false),
                FrontendFormContractField::make('timezone', 'timezone', required: false),
                FrontendFormContractField::make('daily_prayer_institution_ids', 'array<uuid>', required: false, catalog: route('api.client.catalogs.prayer-institutions')),
                FrontendFormContractField::make('friday_prayer_institution_ids', 'array<uuid>', required: false, catalog: route('api.client.catalogs.prayer-institutions')),
            ],
            'conditional_rules' => [
                ['field' => 'email', 'required_when_missing' => ['phone']],
                ['field' => 'phone', 'required_when_missing' => ['email']],
            ],
            'notification_settings_endpoint' => route('api.notification-settings.show'),
            'notification_update_endpoint' => route('api.notification-settings.update'),
            'mcp_tokens_endpoint' => route('api.client.account-settings.mcp-tokens.index'),
            'mcp_token_store_endpoint' => route('api.client.account-settings.mcp-tokens.store'),
            'mcp_token_revoke_endpoint_template' => route('api.client.account-settings.mcp-tokens.destroy', ['tokenId' => 'token'], false),
            'mcp_token_fields' => [
                FrontendFormContractField::make('name', 'string', required: true, maxLength: 255),
                FrontendFormContractField::make('server', 'string', required: true, allowedValues: array_keys($mcpServers)),
            ],
            'mcp_servers' => $mcpServers,
        ];
    }
}
