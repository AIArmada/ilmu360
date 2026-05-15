<?php

namespace App\Support\SocialMedia;

use App\Enums\SocialMediaPlatform;
use Illuminate\Support\Str;

final class SocialMediaLinkResolver
{
    /**
     * @return array{platform: string, username: string|null, url: string|null}
     */
    public static function normalize(?string $platformInput, mixed $usernameInput, mixed $urlInput): array
    {
        $platform = self::normalizePlatform($platformInput);
        $username = self::normalizeNullableString($usernameInput);
        $url = self::normalizeNullableString($urlInput);

        if (! self::isHandlePlatform($platform)) {
            $normalizedUrl = self::normalizeWebsiteUrl($url, $username);

            return [
                'platform' => $platform,
                'username' => self::isUrlLike((string) $username) ? null : $username,
                'url' => $normalizedUrl,
            ];
        }

        $identifier = self::extractIdentifier($platform, $username, $url);

        if ($identifier === null) {
            return [
                'platform' => $platform,
                'username' => null,
                'url' => self::normalizeWebsiteUrl($url, null),
            ];
        }

        return [
            'platform' => $platform,
            'username' => $identifier,
            // Username is canonical storage. URL is generated dynamically at read-time.
            'url' => null,
        ];
    }

    public static function resolveUrl(?string $platformInput, ?string $username, ?string $fallbackUrl): ?string
    {
        $platform = self::normalizePlatform($platformInput);
        $normalizedUsername = self::normalizeNullableString($username);

        if ($normalizedUsername !== null && self::isHandlePlatform($platform)) {
            return self::buildHandleUrl($platform, $normalizedUsername);
        }

        return self::normalizeWebsiteUrl($fallbackUrl, null);
    }

    public static function displayUsername(?string $platformInput, ?string $username): ?string
    {
        $normalizedUsername = self::normalizeNullableString($username);

        if ($normalizedUsername === null) {
            return null;
        }

        $platform = self::normalizePlatform($platformInput);

        if (! self::isHandlePlatform($platform) || $platform === SocialMediaPlatform::WhatsApp->value) {
            return $normalizedUsername;
        }

        if (! str_starts_with($normalizedUsername, '@')) {
            return '@'.$normalizedUsername;
        }

        return $normalizedUsername;
    }

    private static function normalizePlatform(?string $platformInput): string
    {
        $platform = Str::lower(trim((string) $platformInput));

        if ($platform === 'x') {
            return SocialMediaPlatform::Twitter->value;
        }

        if ($platform === '' || SocialMediaPlatform::tryFrom($platform) === null) {
            return SocialMediaPlatform::Other->value;
        }

        return $platform;
    }

    private static function isHandlePlatform(string $platform): bool
    {
        return in_array(
            $platform,
            [
                SocialMediaPlatform::Facebook->value,
                SocialMediaPlatform::Twitter->value,
                SocialMediaPlatform::Instagram->value,
                SocialMediaPlatform::YouTube->value,
                SocialMediaPlatform::TikTok->value,
                SocialMediaPlatform::Telegram->value,
                SocialMediaPlatform::WhatsApp->value,
                SocialMediaPlatform::LinkedIn->value,
                SocialMediaPlatform::Threads->value,
            ],
            true,
        );
    }

    private static function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private static function normalizeWebsiteUrl(?string $urlInput, ?string $fallback): ?string
    {
        $candidate = $urlInput ?? $fallback;

        if ($candidate === null) {
            return null;
        }

        $prepared = trim($candidate);

        if ($prepared === '') {
            return null;
        }

        if (! preg_match('#^https?://#i', $prepared)) {
            $prepared = 'https://'.$prepared;
        }

        /** @var string|false $validated */
        $validated = filter_var($prepared, FILTER_VALIDATE_URL);

        return is_string($validated) ? $validated : null;
    }

    private static function extractIdentifier(string $platform, ?string $usernameInput, ?string $urlInput): ?string
    {
        if ($usernameInput !== null && ! self::isUrlLike($usernameInput)) {
            return self::sanitizeIdentifier($platform, $usernameInput);
        }

        if ($urlInput !== null) {
            $fromUrl = self::parseIdentifierFromUrl($platform, $urlInput);
            if ($fromUrl !== null) {
                return $fromUrl;
            }
        }

        if ($usernameInput === null) {
            return null;
        }

        if (self::isUrlLike($usernameInput)) {
            $fromUsernameUrl = self::parseIdentifierFromUrl($platform, $usernameInput);
            if ($fromUsernameUrl !== null) {
                return $fromUsernameUrl;
            }
        }

        return self::sanitizeIdentifier($platform, $usernameInput);
    }

    private static function isUrlLike(string $value): bool
    {
        if (preg_match('#^https?://#i', $value) === 1) {
            return true;
        }

        return str_contains($value, '/')
            || str_contains($value, '.')
            || str_contains($value, '?');
    }

    private static function parseIdentifierFromUrl(string $platform, string $raw): ?string
    {
        $url = trim($raw);

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        /** @var array{host?: mixed, path?: mixed, query?: mixed}|false $parts */
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $host = Str::lower((string) ($parts['host'] ?? ''));
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $query = (string) ($parts['query'] ?? '');
        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));

        return match ($platform) {
            SocialMediaPlatform::Instagram->value => self::parseInstagram($host, $segments),
            SocialMediaPlatform::TikTok->value => self::parseTiktok($host, $segments),
            SocialMediaPlatform::Twitter->value => self::parseTwitter($host, $segments),
            SocialMediaPlatform::Facebook->value => self::parseFacebook($host, $segments, $query, $path),
            SocialMediaPlatform::Telegram->value => self::parseTelegram($host, $segments),
            SocialMediaPlatform::WhatsApp->value => self::parseWhatsApp($host, $segments, $query),
            SocialMediaPlatform::LinkedIn->value => self::parseLinkedIn($host, $segments),
            SocialMediaPlatform::YouTube->value => self::parseYouTube($host, $segments),
            SocialMediaPlatform::Threads->value => self::parseGeneric($platform, $host, ['threads.net'], $segments),
            default => null,
        };
    }

    /**
     * @param  list<string>  $segments
     * @param  list<string>  $allowedHosts
     */
    private static function parseGeneric(string $platform, string $host, array $allowedHosts, array $segments): ?string
    {
        $isAllowed = array_any($allowedHosts, fn ($allowedHost) => $host === $allowedHost || str_ends_with($host, '.'.$allowedHost));
        if (! $isAllowed || $segments === []) {
            return null;
        }

        return self::sanitizeIdentifier($platform, $segments[0]);
    }

    /**
     * @param  list<string>  $segments
     */
    private static function parseInstagram(string $host, array $segments): ?string
    {
        if (! in_array($host, ['instagram.com', 'www.instagram.com'], true) || $segments === []) {
            return null;
        }

        $first = Str::lower($segments[0]);
        if (in_array($first, ['p', 'reel', 'reels', 'stories', 'explore', 'accounts'], true)) {
            return null;
        }

        return self::sanitizeIdentifier(SocialMediaPlatform::Instagram->value, $segments[0]);
    }

    /**
     * @param  list<string>  $segments
     */
    private static function parseTiktok(string $host, array $segments): ?string
    {
        if (! in_array($host, ['tiktok.com', 'www.tiktok.com'], true) || $segments === []) {
            return null;
        }

        return self::sanitizeIdentifier(SocialMediaPlatform::TikTok->value, $segments[0]);
    }

    /**
     * @param  list<string>  $segments
     */
    private static function parseTwitter(string $host, array $segments): ?string
    {
        if (! in_array($host, ['twitter.com', 'www.twitter.com', 'x.com', 'www.x.com'], true) || $segments === []) {
            return null;
        }

        $first = Str::lower($segments[0]);
        if (in_array($first, ['home', 'explore', 'notifications', 'messages', 'compose', 'search', 'settings', 'i'], true)) {
            return null;
        }

        return self::sanitizeIdentifier(SocialMediaPlatform::Twitter->value, $segments[0]);
    }

    /**
     * @param  list<string>  $segments
     */
    private static function parseFacebook(string $host, array $segments, string $query, string $path): ?string
    {
        if (! in_array($host, ['facebook.com', 'www.facebook.com', 'm.facebook.com', 'fb.com'], true)) {
            return null;
        }

        if (Str::lower($path) === 'profile.php' && $query !== '') {
            parse_str($query, $params);
            $profileId = $params['id'] ?? null;

            return is_string($profileId) ? self::sanitizeIdentifier(SocialMediaPlatform::Facebook->value, $profileId) : null;
        }

        if ($segments === []) {
            return null;
        }

        $first = Str::lower($segments[0]);
        if (in_array($first, ['groups', 'watch', 'reel', 'reels', 'events', 'marketplace', 'gaming', 'login'], true)) {
            return null;
        }

        return self::sanitizeIdentifier(SocialMediaPlatform::Facebook->value, $segments[0]);
    }

    /**
     * @param  list<string>  $segments
     */
    private static function parseTelegram(string $host, array $segments): ?string
    {
        if (! in_array($host, ['t.me', 'telegram.me', 'www.t.me'], true) || $segments === []) {
            return null;
        }

        $first = Str::lower($segments[0]);
        if (in_array($first, ['joinchat', 'addstickers', 'share', 's'], true)) {
            return null;
        }

        return self::sanitizeIdentifier(SocialMediaPlatform::Telegram->value, $segments[0]);
    }

    /**
     * @param  list<string>  $segments
     */
    private static function parseWhatsApp(string $host, array $segments, string $query): ?string
    {
        if (in_array($host, ['wa.me', 'www.wa.me'], true) && $segments !== []) {
            return self::sanitizeIdentifier(SocialMediaPlatform::WhatsApp->value, $segments[0]);
        }

        if (in_array($host, ['api.whatsapp.com', 'whatsapp.com', 'www.whatsapp.com'], true) && $query !== '') {
            parse_str($query, $params);
            $phone = $params['phone'] ?? null;

            return is_string($phone) ? self::sanitizeIdentifier(SocialMediaPlatform::WhatsApp->value, $phone) : null;
        }

        return null;
    }

    /**
     * @param  list<string>  $segments
     */
    private static function parseLinkedIn(string $host, array $segments): ?string
    {
        if (! in_array($host, ['linkedin.com', 'www.linkedin.com'], true) || $segments === []) {
            return null;
        }

        if (in_array(Str::lower($segments[0]), ['in', 'company', 'school', 'showcase'], true) && isset($segments[1])) {
            return self::sanitizeIdentifier(SocialMediaPlatform::LinkedIn->value, $segments[0].'/'.$segments[1]);
        }

        return self::sanitizeIdentifier(SocialMediaPlatform::LinkedIn->value, $segments[0]);
    }

    /**
     * @param  list<string>  $segments
     */
    private static function parseYouTube(string $host, array $segments): ?string
    {
        if (! in_array($host, ['youtube.com', 'www.youtube.com'], true) || $segments === []) {
            return null;
        }

        if (str_starts_with($segments[0], '@')) {
            return self::sanitizeIdentifier(SocialMediaPlatform::YouTube->value, $segments[0]);
        }

        if (in_array(Str::lower($segments[0]), ['channel', 'user', 'c'], true) && isset($segments[1])) {
            return self::sanitizeIdentifier(SocialMediaPlatform::YouTube->value, $segments[0].'/'.$segments[1]);
        }

        if (! in_array(Str::lower($segments[0]), ['watch', 'shorts', 'playlist', 'results', 'feed'], true)) {
            return self::sanitizeIdentifier(SocialMediaPlatform::YouTube->value, $segments[0]);
        }

        return null;
    }

    private static function sanitizeIdentifier(string $platform, string $value): ?string
    {
        $identifier = trim($value);
        $identifier = urldecode($identifier);
        $identifier = preg_replace('/[?#].*$/', '', $identifier) ?? $identifier;
        $identifier = trim($identifier, '/');
        $identifier = ltrim($identifier, '@');

        if ($platform === SocialMediaPlatform::WhatsApp->value) {
            $identifier = preg_replace('/\D+/', '', $identifier) ?? '';
        } else {
            $identifier = preg_replace('/\s+/', '', $identifier) ?? $identifier;
        }

        return $identifier !== '' ? $identifier : null;
    }

    private static function buildHandleUrl(string $platform, string $username): ?string
    {
        return match ($platform) {
            SocialMediaPlatform::Facebook->value => 'https://www.facebook.com/'.$username,
            SocialMediaPlatform::Twitter->value => 'https://x.com/'.$username,
            SocialMediaPlatform::Instagram->value => 'https://www.instagram.com/'.$username,
            SocialMediaPlatform::YouTube->value => self::buildYoutubeUrl($username),
            SocialMediaPlatform::TikTok->value => 'https://www.tiktok.com/@'.$username,
            SocialMediaPlatform::Telegram->value => 'https://t.me/'.$username,
            SocialMediaPlatform::WhatsApp->value => 'https://wa.me/'.$username,
            SocialMediaPlatform::LinkedIn->value => self::buildLinkedinUrl($username),
            SocialMediaPlatform::Threads->value => 'https://www.threads.net/@'.$username,
            default => null,
        };
    }

    private static function buildYoutubeUrl(string $username): string
    {
        if (preg_match('#^(channel|user|c)/#', $username) === 1) {
            return 'https://www.youtube.com/'.$username;
        }

        return 'https://www.youtube.com/@'.$username;
    }

    private static function buildLinkedinUrl(string $username): string
    {
        if (preg_match('#^(in|company|school|showcase)/#', $username) === 1) {
            return 'https://www.linkedin.com/'.$username.'/';
        }

        return 'https://www.linkedin.com/in/'.$username.'/';
    }
}
