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
     */
    public function __construct(
        public AppFeedbackCategory $category,
        public string $title,
        public string $description,
        public ?string $pageUrl,
        public bool $includeDiagnostics,
        public string $submissionKey,
        public array $evidence,
        public ?string $userAgent,
        public ?string $appVersion,
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
            'include_diagnostics' => ['sometimes', 'boolean'],
            'evidence' => ['nullable', 'array', 'max:8'],
            'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.$maxUploadSizeKb],
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

        $includeDiagnostics = (bool) ($validated['include_diagnostics'] ?? false);

        return new self(
            category: AppFeedbackCategory::from((string) $validated['category']),
            title: trim((string) $validated['title']),
            description: trim((string) $validated['description']),
            pageUrl: self::sanitizePageUrl($validated['page_url'] ?? null),
            includeDiagnostics: $includeDiagnostics,
            submissionKey: (string) $validated['submission_key'],
            evidence: $evidence,
            userAgent: $includeDiagnostics ? self::sanitizeUserAgent($request->userAgent()) : null,
            appVersion: $includeDiagnostics ? self::sanitizeAppVersion(config('app.version')) : null,
        );
    }

    /**
     * @return array<string, string>|null
     */
    public function diagnostics(): ?array
    {
        if (! $this->includeDiagnostics) {
            return null;
        }

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

        return hash('sha256', json_encode([
            'category' => $this->category->value,
            'title' => $this->title,
            'description' => $this->description,
            'page_url' => $this->pageUrl,
            'evidence' => $manifest,
        ], JSON_THROW_ON_ERROR));
    }

    public static function sanitizePageUrl(mixed $value): ?string
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
        $appScheme = (string) (parse_url($appUrl, PHP_URL_SCHEME) ?: 'https');
        $host = $parts['host'] ?? null;

        if (is_string($host)) {
            if (! is_string($appHost) || strcasecmp($host, $appHost) !== 0) {
                return null;
            }

            if (! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
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

        if (! str_starts_with($path, '/')) {
            return null;
        }

        if (self::isBlockedPath($path)) {
            return null;
        }

        $origin = is_string($host)
            ? $appScheme.'://'.$appHost
            : rtrim($appUrl, '/');

        return $origin.$path;
    }

    private static function isBlockedPath(string $path): bool
    {
        foreach ([
            '/api', '/admin', '/ahli', '/mcp', '/docs', '/login', '/register', '/password',
            '/oauth', '/auth', '/checkout', '/storage', '/media', '/livewire', '/dashboard', '/sumbangan',
            '/notifications', '/tetapan', '/carian-tersimpan', '/permohonan-keahlian', '/jemputan-ahli', '/jemputan',
            '/lapor', '/maklum-balas',
        ] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
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
