<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Validates outbound HTTP(S) URLs against SSRF targets (private, reserved, loopback,
 * link-local, metadata, and CGNAT address ranges) before requests or redirects execute.
 */
final class PublicUrlGuard
{
    /** @var callable(string): list<string>|null */
    private static $resolver;

    public static function assertSafeUrl(string $url): string
    {
        return self::validateParsedPublicUrl($url)['url'];
    }

    /**
     * Validate a URL for public SSRF safety and return its DNS-pinned HTTP target.
     *
     * @return array{url: string, host: string, port: int, ip: string, curl_resolve: string}
     */
    public static function resolvePinnedTarget(string $url): array
    {
        $validated = self::validateParsedPublicUrl($url);
        $ip = $validated['ips'][0];
        $resolveAddress = str_contains($ip, ':') ? '['.$ip.']' : $ip;

        return [
            'url' => $validated['url'],
            'host' => $validated['host'],
            'port' => $validated['port'],
            'ip' => $ip,
            'curl_resolve' => "{$validated['host']}:{$validated['port']}:{$resolveAddress}",
        ];
    }

    /**
     * @return array{url: string, host: string, port: int, ips: non-empty-list<string>}
     */
    private static function validateParsedPublicUrl(string $url): array
    {
        $trimmed = trim($url);

        if ($trimmed === '') {
            throw new InvalidArgumentException('URL cannot be empty.');
        }

        $parts = parse_url($trimmed);

        if (! is_array($parts)) {
            throw new InvalidArgumentException('URL could not be parsed.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Only HTTP and HTTPS URLs are allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Credentials in URLs are not allowed.');
        }

        $host = strtolower(trim((string) ($parts['host'] ?? ''), '.'));

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')) {
            throw new InvalidArgumentException('Destination host is not allowed.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        if (! in_array($port, [80, 443], true)) {
            throw new InvalidArgumentException('Destination port is not allowed.');
        }

        $ips = self::resolveHostIps($host);

        if ($ips === []) {
            throw new InvalidArgumentException('Destination host could not be resolved.');
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                throw new InvalidArgumentException('Destination resolves to a non-public IP address.');
            }
        }

        return [
            'url' => $trimmed,
            'host' => $host,
            'port' => $port,
            'ips' => $ips,
        ];
    }

    public static function isPublicIp(string $ip): bool
    {
        $normalized = trim($ip, '[]');

        if (filter_var($normalized, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($normalized, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($normalized);

            if ($long === false) {
                return false;
            }

            // Carrier-Grade NAT 100.64.0.0/10 (not excluded by FILTER_FLAG_NO_PRIV_RANGE)
            // and 0.0.0.0/8 ("this network").
            return ($long & 0xFFC00000) !== 0x64400000 && ($long & 0xFF000000) !== 0x00000000;
        }

        $packed = @inet_pton($normalized);

        if ($packed === false || strlen($packed) !== 16) {
            return false;
        }

        // Check IPv4-mapped IPv6 addresses (::ffff:a.b.c.d) against IPv4 rules.
        if (str_starts_with($packed, str_repeat("\x00", 10)."\xff\xff")) {
            $ipv4 = inet_ntop(substr($packed, 12));

            return is_string($ipv4) && self::isPublicIp($ipv4);
        }

        return true;
    }

    /**
     * Follow up to $maxRedirects HTTP redirects safely, verifying each hop through {@see self::assertSafeUrl()}.
     *
     * @param  callable(string, PendingRequest): Response|null  $send
     */
    public static function getFollowingSafeRedirects(
        string $url,
        callable $configureRequest,
        int $maxRedirects = 3,
        ?callable $send = null,
    ): Response {
        $target = self::resolvePinnedTarget($url);
        $current = $target['url'];

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $options = ['allow_redirects' => false];

            if (extension_loaded('curl') && defined('CURLOPT_RESOLVE')) {
                $options['curl'] = [CURLOPT_RESOLVE => [$target['curl_resolve']]];
            }

            $request = Http::withOptions($options);
            $configureRequest($request);

            $response = $send !== null ? $send($current, $request) : $request->get($current);

            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                return $response;
            }

            $location = trim((string) $response->header('Location'));

            if ($location === '' || $hop === $maxRedirects) {
                throw new InvalidArgumentException('Too many or empty redirects from remote host.');
            }

            $next = self::resolveRedirectUrl($current, $location);
            $target = self::resolvePinnedTarget($next);
            $current = $target['url'];
        }

        throw new InvalidArgumentException('Unable to resolve public redirect destination.');
    }

    /**
     * Override DNS lookup in unit tests without network calls.
     *
     * @param  (callable(string): list<string>)|null  $resolver
     */
    public static function fakeResolver(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * @return list<string>
     */
    private static function resolveHostIps(string $host): array
    {
        $unwrapped = trim($host, '[]');

        if (filter_var($unwrapped, FILTER_VALIDATE_IP) !== false) {
            return [$unwrapped];
        }

        if (self::$resolver !== null) {
            return array_values(array_unique((self::$resolver)($unwrapped)));
        }

        $ips = [];

        $v4 = @gethostbynamel($unwrapped);
        if (is_array($v4)) {
            foreach ($v4 as $ip) {
                $ips[] = $ip;
            }
        }

        if (function_exists('dns_get_record')) {
            $aaaa = @dns_get_record($unwrapped, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $record) {
                    if (is_string($record['ipv6'] ?? null)) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private static function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        $parsedLocation = parse_url($location);

        if (is_array($parsedLocation) && isset($parsedLocation['scheme'])) {
            return $location;
        }

        $base = parse_url($baseUrl);

        if (! is_array($base) || ! isset($base['scheme'], $base['host'])) {
            throw new InvalidArgumentException('Invalid base URL for redirect.');
        }

        $port = isset($base['port']) ? ':'.$base['port'] : '';

        if (str_starts_with($location, '//')) {
            return $base['scheme'].':'.$location;
        }

        $origin = $base['scheme'].'://'.$base['host'].$port;

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $basePath = (string) ($base['path'] ?? '/');
        $dir = rtrim(dirname($basePath), '/');

        return $origin.$dir.'/'.$location;
    }
}
