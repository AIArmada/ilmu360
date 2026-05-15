<?php

declare(strict_types=1);

namespace App\Support\Mcp;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class McpFilePayloadNormalizer
{
    /**
     * @var list<string>
     */
    public const array KNOWN_MEDIA_FIELDS = [
        'logo',
        'cover',
        'avatar',
        'poster',
        'front_cover',
        'back_cover',
        'gallery',
        'evidence',
        'qr',
        'main',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, array<string, mixed>>  $mediaFieldContracts
     * @return array{payload: array<string, mixed>, temporary_paths: list<string>}
     */
    public function normalize(array $payload, array $mediaFieldContracts): array
    {
        $temporaryPaths = [];

        try {
            $this->rejectUnsupportedKnownMediaFields($payload, $mediaFieldContracts);

            foreach ($mediaFieldContracts as $field => $contract) {
                if (! array_key_exists($field, $payload) || ! $this->hasMeaningfulValue($payload[$field])) {
                    continue;
                }

                $payload[$field] = $this->normalizeFieldValue($field, $payload[$field], $contract, $temporaryPaths);
            }
        } catch (Throwable $exception) {
            $this->cleanup($temporaryPaths);

            throw $exception;
        }

        return [
            'payload' => $payload,
            'temporary_paths' => $temporaryPaths,
        ];
    }

    /**
     * @param  list<string>  $temporaryPaths
     */
    public function cleanup(array $temporaryPaths): void
    {
        foreach ($temporaryPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, array<string, mixed>>  $mediaFieldContracts
     */
    private function rejectUnsupportedKnownMediaFields(array $payload, array $mediaFieldContracts): void
    {
        $errors = [];

        foreach (self::KNOWN_MEDIA_FIELDS as $field) {
            if (
                ! array_key_exists($field, $payload)
                || array_key_exists($field, $mediaFieldContracts)
                || ! $this->hasMeaningfulValue($payload[$field])
            ) {
                continue;
            }

            $errors[$field] = ['This media field is not supported by the selected MCP write schema.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $contract
     * @param  list<string>  $temporaryPaths
     * @return UploadedFile|list<UploadedFile>
     */
    private function normalizeFieldValue(string $field, mixed $value, array $contract, array &$temporaryPaths): UploadedFile|array
    {
        $type = $contract['type'] ?? null;
        $isMultiple = is_string($type) && str_contains($type, 'array');

        if (! $isMultiple) {
            return $this->uploadedFileFromDescriptor($field, $value, $contract, $temporaryPaths);
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw ValidationException::withMessages([
                $field => ['This MCP media field must be an array of file descriptors.'],
            ]);
        }

        $maxFiles = $this->maxFiles($contract);

        if ($maxFiles !== null && count($value) > $maxFiles) {
            throw ValidationException::withMessages([
                $field => ["This MCP media field may not contain more than {$maxFiles} files."],
            ]);
        }

        return array_values(array_map(
            fn (mixed $item, int $index): UploadedFile => $this->uploadedFileFromDescriptor("{$field}.{$index}", $item, $contract, $temporaryPaths),
            $value,
            array_keys($value),
        ));
    }

    /**
     * @param  array<string, mixed>  $contract
     * @param  list<string>  $temporaryPaths
     */
    private function uploadedFileFromDescriptor(string $field, mixed $value, array $contract, array &$temporaryPaths): UploadedFile
    {
        if (! is_array($value) || array_is_list($value)) {
            Log::debug('mcp.image_upload: descriptor is not an associative array', [
                'field' => $field,
                'type' => gettype($value),
            ]);

            throw ValidationException::withMessages([
                $field => ['This MCP media field must be a file descriptor object.'],
            ]);
        }

        /** @var array<string, mixed> $descriptor */
        $descriptor = $value;
        $fileName = $this->stringValue($descriptor, ['filename', 'file_name', 'fileName', 'name']);
        $base64 = $this->stringValue($descriptor, ['content_base64', 'contentBase64', 'base64', 'data']);
        // Support both content_url and ChatGPT's download_url for fetching files
        $contentUrl = $this->stringValue($descriptor, ['content_url', 'contentUrl', 'url', 'download_url', 'downloadUrl']);
        $mimeType = $this->stringValue($descriptor, ['mime_type', 'mimeType', 'mime']);
        // file_id is metadata from ChatGPT file params (ignored, used for reference only)
        $fileId = $this->stringValue($descriptor, ['file_id', 'fileId']);

        Log::debug('mcp.image_upload: parsing descriptor', [
            'field' => $field,
            'filename' => $fileName,
            'has_base64' => $base64 !== null,
            'has_content_url' => $contentUrl !== null,
            'has_file_id' => $fileId !== null,
            'declared_mime_type' => $mimeType,
            'descriptor_keys' => array_keys($descriptor),
        ]);

        if ($fileName === null) {
            Log::debug('mcp.image_upload: descriptor missing filename', ['field' => $field]);

            throw ValidationException::withMessages([
                $field => ['The MCP file descriptor must include a filename.'],
            ]);
        }

        if ($base64 === null && $contentUrl === null) {
            Log::debug('mcp.image_upload: descriptor missing content source (no base64 or url)', [
                'field' => $field,
                'filename' => $fileName,
                'file_id' => $fileId,
            ]);

            throw ValidationException::withMessages([
                $field => ['The MCP file descriptor must include either content_base64, content_url, or download_url. MCP tools do not accept multipart/form-data payloads. ChatGPT connectors may pass {download_url, file_id}.'],
            ]);
        }

        $decoded = null;

        if ($base64 !== null) {
            if (preg_match('/^data:([^;]+);base64,(.*)$/s', $base64, $matches) === 1) {
                $mimeType ??= $matches[1];
                $base64 = $matches[2];
            }

            $decoded = base64_decode((string) preg_replace('/\s+/', '', $base64), true);

            if (! is_string($decoded)) {
                Log::debug('mcp.image_upload: base64 decode failed', [
                    'field' => $field,
                    'filename' => $fileName,
                    'base64_length' => strlen($base64),
                ]);

                throw ValidationException::withMessages([
                    $field => ['The MCP file descriptor content_base64 is not valid base64.'],
                ]);
            }

            Log::debug('mcp.image_upload: base64 decoded successfully', [
                'field' => $field,
                'filename' => $fileName,
                'decoded_size_bytes' => strlen($decoded),
                'resolved_mime_type' => $mimeType,
            ]);
        } elseif ($contentUrl !== null) {
            $this->assertSafeContentUrl($field, $contentUrl);

            Log::debug('mcp.image_upload: fetching content_url', [
                'field' => $field,
                'filename' => $fileName,
                'url_host' => parse_url($contentUrl, PHP_URL_HOST),
            ]);

            try {
                $response = Http::accept('*/*')
                    ->connectTimeout(5)
                    ->timeout(20)
                    ->withOptions([
                        'allow_redirects' => false,
                    ])
                    ->get($contentUrl);
            } catch (Throwable $exception) {
                Log::debug('mcp.image_upload: content_url fetch threw exception', [
                    'field' => $field,
                    'filename' => $fileName,
                    'error' => $exception->getMessage(),
                ]);

                throw ValidationException::withMessages([
                    $field => ['The MCP file descriptor content_url could not be fetched.'],
                ]);
            }

            if ($response->redirect()) {
                Log::debug('mcp.image_upload: content_url returned a redirect', [
                    'field' => $field,
                    'filename' => $fileName,
                    'status' => $response->status(),
                    'location' => $response->header('Location'),
                ]);

                throw ValidationException::withMessages([
                    $field => ['The MCP file descriptor content_url must not redirect.'],
                ]);
            }

            if (! $response->successful()) {
                Log::debug('mcp.image_upload: content_url returned non-2xx status', [
                    'field' => $field,
                    'filename' => $fileName,
                    'status' => $response->status(),
                ]);

                throw ValidationException::withMessages([
                    $field => ['The MCP file descriptor content_url did not return a successful response.'],
                ]);
            }

            $decoded = $response->body();

            if (! is_string($decoded) || $decoded === '') {
                Log::debug('mcp.image_upload: content_url returned empty body', [
                    'field' => $field,
                    'filename' => $fileName,
                    'status' => $response->status(),
                ]);

                throw ValidationException::withMessages([
                    $field => ['The MCP file descriptor content_url returned an empty body.'],
                ]);
            }

            $mimeType ??= $response->header('Content-Type');

            Log::debug('mcp.image_upload: content_url fetched successfully', [
                'field' => $field,
                'filename' => $fileName,
                'response_size_bytes' => strlen($decoded),
                'resolved_mime_type' => $mimeType,
            ]);
        }

        $mimeType = $this->normalizeMimeType($mimeType);

        if (! is_string($decoded)) {
            Log::debug('mcp.image_upload: decoded content is not a string after processing', [
                'field' => $field,
                'filename' => $fileName,
                'file_id' => $fileId,
            ]);

            throw ValidationException::withMessages([
                $field => is_string($fileId)
                    ? ["The MCP file descriptor (file_id: {$fileId}) could not be decoded."]
                    : ['The MCP file descriptor could not be decoded.'],
            ]);
        }

        $maxSizeBytes = $this->maxFileSizeKb($contract) * 1024;

        if (strlen($decoded) > $maxSizeBytes) {
            Log::debug('mcp.image_upload: decoded file exceeds maximum size', [
                'field' => $field,
                'filename' => $fileName,
                'size_bytes' => strlen($decoded),
                'max_size_bytes' => $maxSizeBytes,
            ]);

            throw ValidationException::withMessages([
                $field => ['The MCP file descriptor exceeds the maximum upload size.'],
            ]);
        }

        $acceptedMimeTypes = $this->acceptedMimeTypes($contract);

        if ($mimeType !== null && $acceptedMimeTypes !== [] && ! in_array($mimeType, $acceptedMimeTypes, true)) {
            Log::debug('mcp.image_upload: mime type not in accepted list', [
                'field' => $field,
                'filename' => $fileName,
                'mime_type' => $mimeType,
                'accepted' => $acceptedMimeTypes,
            ]);

            throw ValidationException::withMessages([
                $field => ['The MCP file descriptor mime_type is not allowed for this field.'],
            ]);
        }

        $temporaryFile = $this->writeTemporaryFile($fileName, $mimeType, $decoded);
        $path = $temporaryFile['path'];
        $temporaryPaths[] = $path;

        Log::debug('mcp.image_upload: temporary file written, creating UploadedFile', [
            'field' => $field,
            'original_filename' => $fileName,
            'stored_filename' => $temporaryFile['file_name'],
            'mime_type' => $mimeType,
            'size_bytes' => strlen($decoded),
        ]);

        return new UploadedFile($path, $temporaryFile['file_name'], $mimeType, null, true);
    }

    /**
     * @param  array<string, mixed>  $descriptor
     * @param  list<string>  $keys
     */
    private function stringValue(array $descriptor, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $descriptor[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @return array{path: string, file_name: string}
     */
    private function writeTemporaryFile(string $fileName, ?string $mimeType, string $contents): array
    {
        $directory = storage_path('app/tmp/mcp-uploads');
        File::ensureDirectoryExists($directory);

        $baseName = Str::of(pathinfo($fileName, PATHINFO_FILENAME))
            ->slug()
            ->limit(80, '')
            ->toString();

        if ($baseName === '') {
            $baseName = 'upload';
        }

        $extension = $this->extensionFromMimeType($mimeType);
        $extension = $extension !== '' ? $extension : strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $safeName = $baseName.($extension !== '' ? ".{$extension}" : '');
        $path = $directory.'/'.(Str::uuid()).'-'.$safeName;

        if (file_put_contents($path, $contents) === false) {
            throw ValidationException::withMessages([
                'media' => ['The MCP file descriptor could not be staged for validation.'],
            ]);
        }

        return [
            'path' => $path,
            'file_name' => $safeName,
        ];
    }

    private function extensionFromMimeType(?string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
            default => '',
        };
    }

    private function normalizeMimeType(?string $mimeType): ?string
    {
        if (! is_string($mimeType)) {
            return null;
        }

        $normalized = strtolower(trim(explode(';', $mimeType)[0] ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return list<string>
     */
    private function acceptedMimeTypes(array $contract): array
    {
        $mimeTypes = $contract['accepted_mime_types'] ?? [];

        if (! is_array($mimeTypes)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $mimeType): ?string {
            if (! is_string($mimeType) || trim($mimeType) === '') {
                return null;
            }

            return $this->normalizeMimeType($mimeType);
        }, $mimeTypes), static fn (mixed $mimeType): bool => is_string($mimeType) && $mimeType !== ''));
    }

    private function assertSafeContentUrl(string $field, string $contentUrl): void
    {
        $parts = parse_url($contentUrl);

        if (! is_array($parts)) {
            throw ValidationException::withMessages([
                $field => ['The MCP file descriptor content_url must be a valid absolute http(s) URL.'],
            ]);
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $user = $parts['user'] ?? null;
        $pass = $parts['pass'] ?? null;

        if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
            throw ValidationException::withMessages([
                $field => ['The MCP file descriptor content_url must be an absolute http(s) URL.'],
            ]);
        }

        if ((is_string($user) && $user !== '') || (is_string($pass) && $pass !== '')) {
            throw ValidationException::withMessages([
                $field => ['The MCP file descriptor content_url must not include user credentials.'],
            ]);
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw ValidationException::withMessages([
                $field => ['The MCP file descriptor content_url host is not allowed.'],
            ]);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (! $this->isPublicIpAddress($host)) {
                throw ValidationException::withMessages([
                    $field => ['The MCP file descriptor content_url host is not allowed.'],
                ]);
            }

            return;
        }

        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw ValidationException::withMessages([
                $field => ['The MCP file descriptor content_url host is not allowed.'],
            ]);
        }

        foreach ($this->resolvedHostIpAddresses($host) as $ipAddress) {
            if (! $this->isPublicIpAddress($ipAddress)) {
                throw ValidationException::withMessages([
                    $field => ['The MCP file descriptor content_url host is not allowed.'],
                ]);
            }
        }
    }

    private function isPublicIpAddress(string $ipAddress): bool
    {
        return filter_var(
            $ipAddress,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /**
     * @return list<string>
     */
    private function resolvedHostIpAddresses(string $host): array
    {
        $ipAddresses = [];

        $ipv4Addresses = gethostbynamel($host);

        if (is_array($ipv4Addresses)) {
            foreach ($ipv4Addresses as $ipAddress) {
                if (filter_var($ipAddress, FILTER_VALIDATE_IP) !== false) {
                    $ipAddresses[] = $ipAddress;
                }
            }
        }

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);

            if (is_array($records)) {
                foreach ($records as $record) {
                    $ipv4 = $record['ip'] ?? null;
                    $ipv6 = $record['ipv6'] ?? null;

                    if (is_string($ipv4) && filter_var($ipv4, FILTER_VALIDATE_IP) !== false) {
                        $ipAddresses[] = $ipv4;
                    }

                    if (is_string($ipv6) && filter_var($ipv6, FILTER_VALIDATE_IP) !== false) {
                        $ipAddresses[] = $ipv6;
                    }
                }
            }
        }

        return array_values(array_unique($ipAddresses));
    }

    /**
     * @param  array<string, mixed>  $contract
     */
    private function maxFileSizeKb(array $contract): int
    {
        $maxFileSizeKb = $contract['max_file_size_kb'] ?? null;

        if (is_int($maxFileSizeKb) && $maxFileSizeKb > 0) {
            return $maxFileSizeKb;
        }

        return (int) ceil(((int) config('media-library.max_file_size', 10 * 1024 * 1024)) / 1024);
    }

    /**
     * @param  array<string, mixed>  $contract
     */
    private function maxFiles(array $contract): ?int
    {
        $maxFiles = $contract['max_files'] ?? null;

        return is_int($maxFiles) && $maxFiles > 0 ? $maxFiles : null;
    }

    private function hasMeaningfulValue(mixed $value): bool
    {
        return match (true) {
            is_string($value) => trim($value) !== '',
            is_array($value) => $value !== [],
            is_bool($value) => $value,
            default => $value !== null,
        };
    }
}
