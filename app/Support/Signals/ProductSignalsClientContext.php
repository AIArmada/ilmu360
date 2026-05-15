<?php

declare(strict_types=1);

namespace App\Support\Signals;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final readonly class ProductSignalsClientContext
{
    /**
     * @return array{origin: string, source: string}|null
     */
    public function explicitHeaderOrigin(Request $request): ?array
    {
        return $this->resolveOriginFromCandidates($request, [
            ['header', 'X-Ilmu360-Client-Origin'],
            ['header', 'X-Client-Origin'],
            ['header', 'X-App-Origin'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function properties(?Request $request): array
    {
        if (! $request instanceof Request) {
            return [
                'client_origin' => 'server',
                'client_origin_source' => 'server',
                'client_platform' => 'server',
                'client_family' => 'server',
                'client_transport' => 'server',
            ];
        }

        $resolvedOrigin = $this->resolveOrigin($request);
        $origin = $resolvedOrigin['origin'];

        $properties = [
            'client_origin' => $origin,
            'client_origin_source' => $resolvedOrigin['source'],
            'client_platform' => $this->platformForOrigin($origin),
            'client_family' => $this->familyForOrigin($origin),
            'client_transport' => $request->is('api/*') ? 'api' : 'web',
        ];

        $clientName = $this->firstFilledValue($request, [
            ['header', 'X-Ilmu360-Client-Name'],
            ['header', 'X-Client-Name'],
            ['query', 'client_name'],
        ]);
        $clientVersion = $this->firstFilledValue($request, [
            ['header', 'X-Ilmu360-Client-Version'],
            ['header', 'X-App-Version'],
            ['header', 'X-Client-Version'],
            ['query', 'client_version'],
            ['query', 'app_version'],
        ]);
        $clientBuild = $this->firstFilledValue($request, [
            ['header', 'X-Ilmu360-Client-Build'],
            ['header', 'X-App-Build'],
            ['query', 'client_build'],
            ['query', 'app_build'],
        ]);

        if ($clientName !== null) {
            $properties['client_name'] = $clientName;
        }

        if ($clientVersion !== null) {
            $properties['client_version'] = $clientVersion;
        }

        if ($clientBuild !== null) {
            $properties['client_build'] = $clientBuild;
        }

        return $properties;
    }

    /**
     * @return array{origin: string, source: string}
     */
    private function resolveOrigin(Request $request): array
    {
        $explicitOrigin = $this->resolveOriginFromCandidates($request, [
            ['header', 'X-Ilmu360-Client-Origin'],
            ['header', 'X-Client-Origin'],
            ['header', 'X-App-Origin'],
            ['query', 'origin'],
            ['header', 'X-Ilmu360-Client-Platform'],
            ['header', 'X-Client-Platform'],
            ['header', 'X-Platform'],
            ['query', 'client_platform'],
            ['query', 'platform'],
        ]);

        if ($explicitOrigin !== null) {
            return $explicitOrigin;
        }

        $userAgentOrigin = $this->detectOriginFromUserAgent($request);

        if ($userAgentOrigin !== null) {
            return [
                'origin' => $userAgentOrigin,
                'source' => 'user_agent',
            ];
        }

        return [
            'origin' => $request->is('api/*') ? 'api' : 'web',
            'source' => 'request_path',
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $candidates
     * @return array{origin: string, source: string}|null
     */
    private function resolveOriginFromCandidates(Request $request, array $candidates): ?array
    {
        foreach ($candidates as [$sourceType, $key]) {
            $value = $this->valueFromRequest($request, $sourceType, $key);
            $origin = $this->normalizeOrigin($value);

            if ($origin !== null) {
                return [
                    'origin' => $origin,
                    'source' => "{$sourceType}:{$key}",
                ];
            }
        }

        return null;
    }

    private function normalizeOrigin(?string $origin): ?string
    {
        if (! is_string($origin)) {
            return null;
        }

        $normalized = Str::lower(trim($origin));

        if ($normalized === '') {
            return null;
        }

        $compact = str_replace(['-', '_', ' '], '', $normalized);

        return match ($compact) {
            'web', 'webapp', 'browser', 'site', 'pwa' => 'web',
            'api', 'rest', 'mobileapi' => 'api',
            'ios', 'iosapp', 'iphone', 'iphoneapp' => 'ios',
            'ipad', 'ipados', 'ipadosapp', 'ipadapp' => 'ipados',
            'android', 'androidapp' => 'android',
            'mac', 'macos', 'macapp' => 'macos',
            'windows', 'windowsapp' => 'windows',
            'linux', 'linuxapp' => 'linux',
            default => Str::limit(
                preg_replace('/[^a-z0-9._-]+/', '-', $normalized) ?: 'unknown',
                64,
                '',
            ),
        };
    }

    private function detectOriginFromUserAgent(Request $request): ?string
    {
        $userAgent = Str::lower((string) $request->userAgent());

        if ($userAgent === '') {
            return null;
        }

        if (str_contains($userAgent, 'ipad')) {
            return 'ipados';
        }

        if (str_contains($userAgent, 'iphone') || str_contains($userAgent, 'ipod')) {
            return 'ios';
        }

        if (str_contains($userAgent, 'android')) {
            return 'android';
        }

        if (str_contains($userAgent, 'macintosh') || str_contains($userAgent, 'mac os')) {
            return 'macos';
        }

        if (str_contains($userAgent, 'windows')) {
            return 'windows';
        }

        if (str_contains($userAgent, 'linux')) {
            return 'linux';
        }

        return null;
    }

    private function platformForOrigin(string $origin): string
    {
        return match ($origin) {
            'web', 'api', 'server' => $origin,
            default => $origin,
        };
    }

    private function familyForOrigin(string $origin): string
    {
        return match ($origin) {
            'ios', 'android', 'ipados' => 'mobile',
            'macos', 'windows', 'linux' => 'desktop',
            'web' => 'web',
            'api' => 'api',
            'server' => 'server',
            default => 'other',
        };
    }

    /**
     * @param  list<array{0: string, 1: string}>  $sources
     */
    private function firstFilledValue(Request $request, array $sources): ?string
    {
        foreach ($sources as [$sourceType, $key]) {
            $value = $this->valueFromRequest($request, $sourceType, $key);

            if ($value !== null) {
                return Str::limit($value, 120, '');
            }
        }

        return null;
    }

    private function valueFromRequest(Request $request, string $sourceType, string $key): ?string
    {
        $value = match ($sourceType) {
            'header' => $request->headers->get($key),
            'query' => $request->query($key),
            default => null,
        };

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
