<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mcp;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController as BaseOAuthRegisterController;
use Laravel\Passport\Passport;

class OAuthRegisterController extends BaseOAuthRegisterController
{
    #[\Override]
    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_name' => ['nullable', 'string', 'min:1', 'max:255', 'required_without:name'],
            'name' => ['nullable', 'string', 'min:1', 'max:255', 'required_without:client_name'],
            'redirect_uris' => ['required', 'array', 'min:1'],
            'redirect_uris.*' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! $this->isValidRedirectUri($value)) {
                    $fail($attribute.' is not a valid URL.');

                    return;
                }

                if (! in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true)) {
                    return;
                }

                if (in_array('*', config('mcp.redirect_domains', []), true)) {
                    return;
                }

                if ($this->hasLocalhostDomain() && $this->isLocalhostUrl($value)) {
                    return;
                }

                if (! $this->isAllowedHttpRedirectUri($value)) {
                    $fail($attribute.' is not a permitted redirect domain.');
                }
            }],
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();

            $isRedirectError = collect($errors->keys())->contains(
                static fn (string $key): bool => str_starts_with($key, 'redirect_uris')
            );

            return response()->json([
                'error' => $isRedirectError ? 'invalid_redirect_uri' : 'invalid_client_metadata',
                'error_description' => $errors->first(),
            ], 400);
        }

        $validated = $validator->validated();
        $clientName = (string) ($validated['client_name'] ?? $validated['name']);

        /** @var array<int, string> $redirectUris */
        $redirectUris = array_map(
            static fn (mixed $redirectUri): string => (string) $redirectUri,
            $validated['redirect_uris'],
        );
        $grantTypes = ['authorization_code', 'refresh_token'];

        $client = Passport::client()->newQuery()->forceCreate([
            'name' => $clientName,
            'secret' => null,
            'provider' => null,
            'revoked' => false,
            'redirect_uris' => $redirectUris,
            'grant_types' => $grantTypes,
        ]);

        return response()->json([
            'client_id' => (string) $client->getKey(),
            'grant_types' => $grantTypes,
            'response_types' => ['code'],
            'redirect_uris' => $redirectUris,
            'scope' => 'mcp:use',
            'token_endpoint_auth_method' => 'none',
        ]);
    }

    private function hasLocalhostDomain(): bool
    {
        /** @var array<int, string> $domains */
        $domains = config('mcp.redirect_domains', []);

        return collect($domains)->contains(static fn (string $domain): bool => in_array(
            rtrim(Str::after($domain, '://'), '/'),
            ['localhost', '127.0.0.1', '[::1]'],
            true,
        ));
    }

    private function isAllowedHttpRedirectUri(string $uri): bool
    {
        $normalizedUri = $this->normalizeHttpRedirectBase($uri);

        if (! is_string($normalizedUri)) {
            return false;
        }

        /** @var array<int, string> $domains */
        $domains = config('mcp.redirect_domains', []);

        return collect($domains)
            ->map(fn (string $domain): ?string => $this->normalizeHttpRedirectBase($domain))
            ->filter(static fn (mixed $domain): bool => is_string($domain))
            ->contains($normalizedUri);
    }

    private function normalizeHttpRedirectBase(string $uri): ?string
    {
        $scheme = parse_url($uri, PHP_URL_SCHEME);
        $host = parse_url($uri, PHP_URL_HOST);

        if (! is_string($scheme) || ! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if (! is_string($host) || $host === '') {
            return null;
        }

        $port = parse_url($uri, PHP_URL_PORT);
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $normalizedPort = is_int($port) && $port !== $defaultPort ? ':'.$port : '';

        return sprintf(
            '%s://%s%s/',
            $scheme,
            $host,
            $normalizedPort,
        );
    }
}
