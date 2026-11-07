<?php

declare(strict_types=1);

namespace App\Support\AppFeedback;

use App\Enums\AppFeedbackCategory;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

final readonly class AppFeedbackInput
{
    /**
     * @param  list<UploadedFile>  $evidence
     * @param  array<string, string|null>  $details
     */
    public function __construct(
        public AppFeedbackCategory $category,
        public string $title,
        public string $description,
        public ?string $pageUrl,
        public string $submissionKey,
        public array $evidence,
        public ?string $userAgent,
        public ?string $appVersion,
        public array $details = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(int $maxUploadSizeKb): array
    {
        return [
            'submission_key' => ['required', 'uuid'],
            'category' => ['required', Rule::enum(AppFeedbackCategory::class)],
            'title' => ['required', 'string', 'min:3', 'max:180'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'page_url' => ['nullable', 'string', 'max:2048'],
            'details' => ['nullable', 'array'],
            'details.steps' => ['nullable', 'string', 'max:2000'],
            'details.expected' => ['nullable', 'string', 'max:2000'],
            'details.actual' => ['nullable', 'string', 'max:2000'],
            'details.problem' => ['nullable', 'string', 'max:2000'],
            'details.suggestion' => ['nullable', 'string', 'max:2000'],
            'details.frequency' => ['nullable', 'string', 'max:50'],
            'evidence' => ['nullable', 'array', 'max:8'],
            'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'extensions:jpg,jpeg,png,webp,pdf', 'max:'.$maxUploadSizeKb],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated, Request $request): self
    {
        /** @var list<UploadedFile> $evidence */
        $evidence = array_values(array_filter(
            is_array($validated['evidence'] ?? null) ? $validated['evidence'] : [],
            static fn (mixed $file): bool => $file instanceof UploadedFile,
        ));

        $details = [];

        if (is_array($validated['details'] ?? null)) {
            foreach (['steps', 'expected', 'actual', 'problem', 'suggestion', 'frequency'] as $key) {
                $detailValue = $validated['details'][$key] ?? null;

                if (! is_string($detailValue)) {
                    continue;
                }

                $detailValue = trim($detailValue);

                if ($detailValue !== '') {
                    $details[$key] = mb_substr($detailValue, 0, 2000);
                }
            }

            ksort($details);
        }

        return new self(
            category: AppFeedbackCategory::from((string) $validated['category']),
            title: trim((string) $validated['title']),
            description: trim((string) $validated['description']),
            pageUrl: self::sanitizePageUrl($validated['page_url'] ?? null, $request),
            submissionKey: (string) $validated['submission_key'],
            evidence: $evidence,
            userAgent: self::sanitizeUserAgent($request->userAgent()),
            appVersion: self::sanitizeAppVersion(config('app.version')),
            details: $details,
        );
    }

    /**
     * @return array<string, string>|null
     */
    public function diagnostics(): ?array
    {
        $diagnostics = [];

        if (is_string($this->userAgent) && $this->userAgent !== '') {
            $diagnostics['user_agent'] = $this->userAgent;
        }

        if (is_string($this->appVersion) && $this->appVersion !== '') {
            $diagnostics['app_version'] = $this->appVersion;
        }

        return $diagnostics === [] ? null : $diagnostics;
    }

    public function payloadHash(): string
    {
        $manifest = array_map(static function (UploadedFile $file): array {
            $path = $file->getRealPath();
            $contentHash = is_string($path) && is_file($path)
                ? hash_file('sha256', $path)
                : null;

            return [
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $file->getMimeType(),
                'hash' => $contentHash,
            ];
        }, $this->evidence);

        usort($manifest, static fn (array $a, array $b): int => [$a['hash'] ?? '', $a['name'] ?? '', $a['size'] ?? 0, $a['mime'] ?? '']
            <=> [$b['hash'] ?? '', $b['name'] ?? '', $b['size'] ?? 0, $b['mime'] ?? '']);

        return hash('sha256', json_encode([
            'category' => $this->category->value,
            'title' => $this->title,
            'description' => $this->description,
            'details' => $this->details,
            'evidence' => $manifest,
        ], JSON_THROW_ON_ERROR));
    }

    public static function sanitizePageUrl(mixed $value, ?Request $request = null): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > 2048) {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        $parts = parse_url($value);

        if ($parts === false) {
            return null;
        }

        $appUrl = (string) config('app.url');
        $appHost = parse_url($appUrl, PHP_URL_HOST);
        $host = $parts['host'] ?? null;
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if ($scheme !== '' && ! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if (is_string($host)) {
            $requestHost = $request?->getHost();

            $matchesApp = is_string($appHost) && strcasecmp($host, $appHost) === 0;
            $matchesRequest = is_string($requestHost) && $requestHost !== '' && strcasecmp($host, $requestHost) === 0;

            if (! $matchesApp && ! $matchesRequest) {
                return null;
            }

            if (isset($parts['user']) || isset($parts['pass'])) {
                return null;
            }
        }

        $path = (string) ($parts['path'] ?? '/');

        if ($path === '') {
            $path = '/';
        }

        if ($host === null && $scheme === '' && ! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        if (! str_starts_with($path, '/')) {
            return null;
        }

        if (self::isBlockedPath($path)) {
            return null;
        }

        $origin = rtrim($appUrl, '/');

        return $origin.$path;
    }

    private static function isBlockedPath(string $path): bool
    {
        foreach ([
            '/maklum-balas',
            '/livewire',
            '/storage',
            '/media',
            '/api',
            '/mcp',
            '/docs',
            '/login',
            '/register',
            '/password',
            '/oauth',
            '/auth',
            '/checkout',
        ] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        if (preg_match('#^/jemputan(?:-ahli)?(?:/|$)#', $path) === 1) {
            return true;
        }

        return preg_match('#^/majlis/[^/]+/(?:pas|pendaftaran|refund)(?:/|$)#', $path) === 1
            || preg_match('#^/majlis/[^/]+/[^/]+/kalendar\.ics$#', $path) === 1;
    }

    private static function sanitizeUserAgent(?string $userAgent): ?string
    {
        if (! is_string($userAgent)) {
            return null;
        }

        $userAgent = preg_replace('/[\x00-\x1F\x7F]/', ' ', $userAgent) ?? '';
        $userAgent = trim(preg_replace('/\s+/', ' ', $userAgent) ?? '');

        if ($userAgent === '') {
            return null;
        }

        return mb_substr($userAgent, 0, 512);
    }

    private static function sanitizeAppVersion(mixed $version): ?string
    {
        if (! is_string($version)) {
            return null;
        }

        $version = preg_replace('/[\x00-\x1F\x7F]/', '', $version) ?? '';
        $version = trim($version);

        return $version === '' ? null : mb_substr($version, 0, 100);
    }
}
