<?php

declare(strict_types=1);

namespace App\Platform\Location;

use AIArmada\Contacting\Enums\SocialPlatform;
use AIArmada\Contacting\Support\SocialProfileConfig;
use App\Platform\Http\PublicUrlGuard;
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
            try {
                $target = PublicUrlGuard::resolvePinnedTarget($url);
            } catch (\Throwable) {
                return null;
            }

            try {
                $curlOptions = [
                    CURLOPT_TIMEOUT_MS => 5000,
                    CURLOPT_CONNECTTIMEOUT_MS => 2000,
                    CURLOPT_LOW_SPEED_LIMIT => 1024,
                    CURLOPT_LOW_SPEED_TIME => 5,
                    CURLOPT_MAXFILESIZE => self::MaxHtmlBytes + 1,
                ];

                if (extension_loaded('curl') && defined('CURLOPT_RESOLVE')) {
                    $curlOptions[CURLOPT_RESOLVE] = [$target['curl_resolve']];
                }

                $response = Http::accept('text/html')
                    ->withOptions([
                        'allow_redirects' => false,
                        'stream' => true,
                        'curl' => $curlOptions,
                    ])
                    ->connectTimeout(2)
                    ->timeout(5)
                    ->get($target['url']);
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

    private function isAbsoluteHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }
}
