<?php

declare(strict_types=1);

$parseList = static function (?string $value, bool $trimTrailingSlash = false): array {
    if (! is_string($value) || trim($value) === '') {
        return [];
    }

    return array_values(array_filter(array_map(
        static function (string $item) use ($trimTrailingSlash): ?string {
            $normalized = trim($item);

            if ($normalized === '') {
                return null;
            }

            return $trimTrailingSlash ? rtrim($normalized, '/') : $normalized;
        },
        explode(',', $value),
    )));
};

$appUrl = rtrim((string) env('APP_URL', 'http://localhost'), '/');

$redirectDomains = array_values(array_unique(array_filter([
    $appUrl,
    'http://localhost',
    'http://127.0.0.1',
    'http://[::1]',
    'https://chatgpt.com',
    ...$parseList(env('MCP_REDIRECT_DOMAINS'), true),
])));

$customSchemes = array_values(array_unique($parseList(env('MCP_CUSTOM_SCHEMES'))));

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | These domains are the domains that OAuth clients are permitted to use
    | for redirect URIs. Each domain should be specified with its scheme
    | and host. Domains not in this list will raise validation errors.
    |
    | Extend this allowlist with MCP_REDIRECT_DOMAINS as a comma-separated
    | list when you add new hosted MCP clients.
    |
    */

    'redirect_domains' => $redirectDomains,

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Native desktop OAuth clients may use private-use URI schemes for
    | callback redirects. Keep this list empty unless a supported client
    | requires one. Configure MCP_CUSTOM_SCHEMES as a comma-separated list
    | when you need to allow schemes such as vscode or claude.
    |
    */

    'custom_schemes' => $customSchemes,

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | Here you may configure the OAuth authorization server issuer identifier
    | per RFC 8414. This value appears in your protected resource and auth
    | server metadata endpoints. When null, this defaults to `url('/')`.
    |
    */

    'authorization_server' => env('MCP_AUTHORIZATION_SERVER', $appUrl),

];
