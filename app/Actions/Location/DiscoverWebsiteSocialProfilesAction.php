<?php

declare(strict_types=1);

namespace App\Actions\Location;

use AIArmada\Contacting\Enums\SocialPlatform;
use AIArmada\Contacting\Support\SocialProfileConfig;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\Http;
use Lorisleiva\Actions\Concerns\AsAction;

class DiscoverWebsiteSocialProfilesAction
{
    use AsAction;

    private const int MaxHtmlBytes = 524288;

    public function __construct(private readonly SocialProfileConfig $socialProfileConfig) {}

    /**
     * @return list<array{platform: string, handle: string, url: string, is_public: bool, is_primary: bool}>
     */
    public function handle(?string $websiteUrl): array
    {
        if (! is_string($websiteUrl)) {
            return [];
        }

        $directProfiles = $this->extractSocialProfilesFromUrls([$websiteUrl]);
        if ($directProfiles !== []) {
            return $directProfiles;
        }

        try {
            $html = $this->fetchPublicHtml($websiteUrl);
        } catch (\Throwable) {
            return [];
        }

        return $html === null
            ? []
            : array_merge($directProfiles, $this->extractSocialProfiles($html));
    }

    private function fetchPublicHtml(string $url): ?string
    {
        for ($redirectCount = 0; $redirectCount <= 3; $redirectCount++) {
            $target = $this->publicTarget($url);

            if ($target === null) {
                return null;
            }

            [$host, $port, $ip] = $target;
            $resolveAddress = str_contains($ip, ':') ? '['.$ip.']' : $ip;

            try {
                $response = Http::accept('text/html')
                    ->withOptions([
                        'allow_redirects' => false,
                        'stream' => true,
                        'curl' => [
                            CURLOPT_RESOLVE => ["{$host}:{$port}:{$resolveAddress}"],
                            CURLOPT_TIMEOUT_MS => 5000,
                            CURLOPT_CONNECTTIMEOUT_MS => 2000,
                            CURLOPT_LOW_SPEED_LIMIT => 1024,
                            CURLOPT_LOW_SPEED_TIME => 5,
                            CURLOPT_MAXFILESIZE => self::MaxHtmlBytes + 1,
                        ],
                    ])
                    ->connectTimeout(2)
                    ->timeout(5)
                    ->get($url);
                $stream = $response->toPsrResponse()->getBody();

                try {
                    if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                        $location = $response->header('Location');
                        $redirectUrl = is_string($location) ? $this->resolveRedirectUrl($url, $location) : null;

                        if ($redirectUrl === null || $redirectCount === 3) {
                            return null;
                        }

                        $url = $redirectUrl;

                        continue;
                    }

                    if (! $response->successful() || str_contains(strtolower((string) $response->header('Content-Type')), 'text/html') === false) {
                        return null;
                    }

                    $contentLength = $response->header('Content-Length');

                    if (is_numeric($contentLength) && (int) $contentLength > self::MaxHtmlBytes) {
                        return null;
                    }

                    $html = '';

                    while (! $stream->eof() && strlen($html) < self::MaxHtmlBytes) {
                        $remainingBytes = self::MaxHtmlBytes - strlen($html);
                        $chunk = $stream->read(min(8192, $remainingBytes));

                        if ($chunk === '') {
                            return null;
                        }

                        $html .= $chunk;
                    }

                    return $html;
                } finally {
                    $stream->close();
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function resolveRedirectUrl(string $currentUrl, string $location): ?string
    {
        $location = trim($location);

        if ($location === '' || preg_match('/[\x00-\x20]/', $location) === 1) {
            return null;
        }

        if ($this->isAbsoluteHttpUrl($location)) {
            return $location;
        }

        $current = parse_url($currentUrl);
        $redirect = parse_url($location);

        if (! is_array($current) || ! is_string($current['scheme'] ?? null) || ! is_string($current['host'] ?? null) || ! is_array($redirect)) {
            return null;
        }

        $scheme = is_string($redirect['scheme'] ?? null) ? $redirect['scheme'] : $current['scheme'];
        $host = is_string($redirect['host'] ?? null) ? $redirect['host'] : $current['host'];
        $port = is_int($redirect['port'] ?? null) ? ':'.$redirect['port'] : '';
        $path = is_string($redirect['path'] ?? null) ? $redirect['path'] : '';

        if ($path === '') {
            $path = is_string($current['path'] ?? null) ? $current['path'] : '/';
        } elseif (! str_starts_with($path, '/')) {
            $currentPath = is_string($current['path'] ?? null) ? $current['path'] : '/';
            $path = rtrim(dirname($currentPath), '/').'/'.$path;
        }

        $query = is_string($redirect['query'] ?? null)
            ? '?'.$redirect['query']
            : (isset($redirect['path']) ? '' : (is_string($current['query'] ?? null) ? '?'.$current['query'] : ''));

        return $scheme.'://'.$host.$port.$path.$query;
    }

    /**
     * @param  list<string>  $urls
     * @return list<array{platform: string, handle: string, url: string, is_public: bool, is_primary: bool}>
     */
    public function extractSocialProfilesFromUrls(array $urls): array
    {
        $profiles = [];

        foreach ($urls as $url) {
            if (! $this->isAbsoluteHttpUrl($url)) {
                continue;
            }

            foreach (SocialPlatform::cases() as $platform) {
                if (in_array($platform, [SocialPlatform::Website, SocialPlatform::Other], true)) {
                    continue;
                }

                $handle = $this->socialProfileConfig->extractHandle($platform->value, $url);

                if (! is_string($handle) || $handle === '') {
                    continue;
                }

                $profiles[] = [
                    'platform' => $platform->value,
                    'handle' => $handle,
                    'url' => $url,
                    'is_public' => true,
                    'is_primary' => false,
                ];

                break;
            }
        }

        return $this->uniqueProfiles($profiles);
    }

    /**
     * @return list<array{platform: string, handle: string, url: string, is_public: bool, is_primary: bool}>
     */
    public function extractSocialProfiles(string $html): array
    {
        $document = new DOMDocument;
        $previousErrors = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        $profiles = [];
        $seen = [];

        foreach ($document->getElementsByTagName('a') as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }

            $url = trim($anchor->getAttribute('href'));

            if (! $this->isAbsoluteHttpUrl($url)) {
                continue;
            }

            foreach (SocialPlatform::cases() as $platform) {
                if (in_array($platform, [SocialPlatform::Website, SocialPlatform::Other], true)) {
                    continue;
                }

                $handle = $this->socialProfileConfig->extractHandle($platform->value, $url);

                if (! is_string($handle) || $handle === '' || isset($seen[$platform->value.'|'.$handle])) {
                    continue;
                }

                $seen[$platform->value.'|'.$handle] = true;
                $profiles[] = [
                    'platform' => $platform->value,
                    'handle' => $handle,
                    'url' => $url,
                    'is_public' => true,
                    'is_primary' => false,
                ];

                break;
            }

            if (count($profiles) >= 30) {
                break;
            }
        }

        return $this->uniqueProfiles($profiles);
    }

    /**
     * @param  list<array{platform: string, handle: string, url: string, is_public: bool, is_primary: bool}>  $profiles
     * @return list<array{platform: string, handle: string, url: string, is_public: bool, is_primary: bool}>
     */
    private function uniqueProfiles(array $profiles): array
    {
        return collect($profiles)
            ->unique(fn (array $profile): string => $profile['platform'].'|'.$profile['handle'])
            ->values()
            ->all();
    }

    /**
     * @return array{string, int, string}|null
     */
    private function publicTarget(string $url): ?array
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) && is_string($parts['scheme'] ?? null) ? strtolower($parts['scheme']) : null;
        $host = is_array($parts) && is_string($parts['host'] ?? null) ? strtolower(rtrim($parts['host'], '.')) : null;
        $port = is_array($parts) && is_int($parts['port'] ?? null) ? $parts['port'] : ($scheme === 'https' ? 443 : 80);

        if (
            ! in_array($scheme, ['http', 'https'], true)
            || ! is_string($host)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || ! in_array($port, [80, 443], true)
            || ! $this->isAbsoluteHttpUrl($url)
        ) {
            return null;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : $this->resolveAddresses($host);

        if ($addresses === [] || collect($addresses)->contains(fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false)) {
            return null;
        }

        $ip = $addresses[0];

        return is_string($ip) && extension_loaded('curl') && defined('CURLOPT_RESOLVE')
            ? [$host, $port, $ip]
            : null;
    }

    /** @return list<string> */
    private function resolveAddresses(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);

        if (! is_array($records)) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip)) {
                $addresses[] = $ip;
            }
        }

        return array_values(array_unique($addresses));
    }

    private function isAbsoluteHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }
}
