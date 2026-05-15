<?php

declare(strict_types=1);

namespace App\Support\Auth;

final class SocialiteProviderConfiguration
{
    public static function isConfigured(string $provider): bool
    {
        $config = self::serviceConfig($provider);

        if (! self::hasRequiredConfiguration($config)) {
            return false;
        }

        // Google OAuth does not accept arbitrary `.test` callback domains, so do not
        // expose a broken social login CTA on local Herd-style environments.
        return ! (app()->environment('local') && self::usesUnsupportedLocalRedirect($config['redirect'] ?? null));
    }

    public static function supportsTokenExchange(string $provider): bool
    {
        return self::hasRequiredConfiguration(self::serviceConfig($provider));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function serviceConfig(string $provider): ?array
    {
        $config = config("services.{$provider}");

        if (! is_array($config)) {
            return null;
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>|null  $config
     */
    private static function hasRequiredConfiguration(?array $config): bool
    {
        if ($config === null) {
            return false;
        }

        return filled($config['client_id'] ?? null)
            && filled($config['client_secret'] ?? null)
            && filled($config['redirect'] ?? null);
    }

    private static function usesUnsupportedLocalRedirect(mixed $redirect): bool
    {
        if (! is_string($redirect) || $redirect === '') {
            return false;
        }

        $host = parse_url($redirect, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        return $host === '.test' || str_ends_with($host, '.test');
    }
}
