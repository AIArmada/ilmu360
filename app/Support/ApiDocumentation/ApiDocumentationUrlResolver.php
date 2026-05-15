<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation;

class ApiDocumentationUrlResolver
{
    public function docsUrl(): string
    {
        return $this->joinPath($this->apiOrigin(), 'docs');
    }

    public function docsJsonUrl(): string
    {
        return $this->joinPath($this->apiOrigin(), 'docs.json');
    }

    public function apiBaseUrl(): string
    {
        $apiPath = trim((string) config('scramble.api_path', 'api/v1'), '/');

        if ($apiPath === '') {
            return $this->apiOrigin();
        }

        return $this->joinPath($this->apiOrigin(), $apiPath);
    }

    public function apiDomain(): ?string
    {
        $configuredDomain = trim((string) config('scramble.api_domain'));

        return $configuredDomain === ''
            ? null
            : $this->normalizeHost($configuredDomain);
    }

    public function apiOrigin(): string
    {
        $configuredDomain = trim((string) config('scramble.api_domain'));

        if ($configuredDomain !== '') {
            if ($this->isHttpUrl($configuredDomain)) {
                return rtrim($configuredDomain, '/');
            }

            return $this->defaultScheme().'://'.$this->normalizeHost($configuredDomain);
        }

        $apiDomain = $this->apiDomain();

        if ($apiDomain === null) {
            return rtrim(url('/'), '/');
        }

        return $this->defaultScheme().'://'.$apiDomain;
    }

    private function defaultScheme(): string
    {
        $appUrlScheme = parse_url((string) config('app.url'), PHP_URL_SCHEME);

        return is_string($appUrlScheme) && $appUrlScheme !== ''
            ? strtolower($appUrlScheme)
            : 'https';
    }

    private function normalizeHost(string $domain): string
    {
        if ($this->isHttpUrl($domain)) {
            $host = parse_url($domain, PHP_URL_HOST);

            return is_string($host)
                ? strtolower($host)
                : '';
        }

        return strtolower(trim($domain, '/'));
    }

    private function isHttpUrl(string $value): bool
    {
        return str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
    }

    private function joinPath(string $origin, string $path): string
    {
        return rtrim($origin, '/').'/'.ltrim($path, '/');
    }
}
