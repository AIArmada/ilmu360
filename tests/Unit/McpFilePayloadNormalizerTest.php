<?php

declare(strict_types=1);

use App\Support\Mcp\McpFilePayloadNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('rejects non-canonical MCP media descriptor field names', function () {
    $normalizer = app(McpFilePayloadNormalizer::class);

    try {
        $normalizer->normalize(
            payload: [
                'avatar' => [
                    'fileName' => 'person-avatar.png',
                    'mimeType' => 'image/png',
                    'contentBase64' => base64_encode('fake-image-bytes'),
                ],
            ],
            mediaFieldContracts: [
                'avatar' => [
                    'type' => 'file',
                    'accepted_mime_types' => ['image/png'],
                    'max_file_size_kb' => 512,
                ],
            ],
        );

        $this->fail('Expected ValidationException to be thrown.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['avatar'][0] ?? null)
            ->toContain('Unsupported MCP file descriptor fields');
    }
});

it('accepts MCP media descriptors with content_url fallback', function () {
    Http::fake([
        'https://example.com/uploads/person-avatar.png' => Http::response('png-bytes', 200, [
            'Content-Type' => 'image/png',
        ]),
    ]);

    $normalizer = app(McpFilePayloadNormalizer::class);

    $normalized = $normalizer->normalize(
        payload: [
            'avatar' => [
                'filename' => 'person-avatar.png',
                'content_url' => 'https://example.com/uploads/person-avatar.png',
            ],
        ],
        mediaFieldContracts: [
            'avatar' => [
                'type' => 'file',
                'accepted_mime_types' => ['image/png'],
                'max_file_size_kb' => 512,
            ],
        ],
    );

    expect($normalized['payload']['avatar'])->toBeInstanceOf(UploadedFile::class)
        ->and($normalized['temporary_paths'])->toHaveCount(1);

    $normalizer->cleanup($normalized['temporary_paths']);
});

it('accepts content_url responses when content-type includes parameters', function () {
    Http::fake([
        'https://example.com/uploads/person-avatar-charset.png' => Http::response('png-bytes', 200, [
            'Content-Type' => 'image/png; charset=binary',
        ]),
    ]);

    $normalizer = app(McpFilePayloadNormalizer::class);

    $normalized = $normalizer->normalize(
        payload: [
            'avatar' => [
                'filename' => 'person-avatar-charset.png',
                'content_url' => 'https://example.com/uploads/person-avatar-charset.png',
            ],
        ],
        mediaFieldContracts: [
            'avatar' => [
                'type' => 'file',
                'accepted_mime_types' => ['image/png'],
                'max_file_size_kb' => 512,
            ],
        ],
    );

    expect($normalized['payload']['avatar'])->toBeInstanceOf(UploadedFile::class)
        ->and($normalized['temporary_paths'])->toHaveCount(1);

    $normalizer->cleanup($normalized['temporary_paths']);
});

it('accepts the ChatGPT download_url descriptor field', function () {
    Http::fake([
        'https://api.openai.com/files/file_id/content' => Http::response('png-bytes', 200, [
            'Content-Type' => 'image/png',
        ]),
    ]);

    $normalizer = app(McpFilePayloadNormalizer::class);

    $normalized = $normalizer->normalize(
        payload: [
            'evidence' => [
                [
                    'filename' => 'proof.png',
                    'download_url' => 'https://api.openai.com/files/file_id/content',
                ],
            ],
        ],
        mediaFieldContracts: [
            'evidence' => [
                'type' => 'array<file>',
                'accepted_mime_types' => ['image/png', 'application/pdf'],
                'max_file_size_kb' => 512,
                'max_files' => 8,
            ],
        ],
    );

    expect($normalized['payload']['evidence'])->toBeArray()->toHaveCount(1)
        ->and($normalized['payload']['evidence'][0])->toBeInstanceOf(UploadedFile::class);

    $normalizer->cleanup($normalized['temporary_paths']);
});

it('rejects ChatGPT file metadata outside event image uploads', function () {
    $normalizer = app(McpFilePayloadNormalizer::class);

    expect(fn () => $normalizer->normalize(
        payload: [
            'evidence' => [
                [
                    'filename' => 'report.pdf',
                    'content_base64' => base64_encode('file-bytes'),
                    'file_id' => 'file_abc123xyz',
                ],
            ],
        ],
        mediaFieldContracts: [
            'evidence' => [
                'type' => 'array<file>',
                'accepted_mime_types' => ['application/pdf'],
                'max_file_size_kb' => 1024,
                'max_files' => 8,
            ],
        ],
    ))->toThrow(ValidationException::class);
});

it('rejects file descriptors that specify more than one content source', function () {
    $normalizer = app(McpFilePayloadNormalizer::class);

    try {
        $normalizer->normalize(
            payload: [
                'avatar' => [
                    'filename' => 'person-avatar.png',
                    'content_base64' => base64_encode('fake-image-bytes'),
                    'content_url' => 'https://example.com/person-avatar.png',
                ],
            ],
            mediaFieldContracts: [
                'avatar' => [
                    'type' => 'file',
                    'accepted_mime_types' => ['image/png'],
                    'max_file_size_kb' => 512,
                ],
            ],
        );

        $this->fail('Expected ValidationException to be thrown.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['avatar'][0] ?? null)
            ->toContain('Provide exactly one file content source.');
    }
});

it('rejects localhost content_url values for MCP descriptors', function () {
    Http::fake();

    $normalizer = app(McpFilePayloadNormalizer::class);

    try {
        $normalizer->normalize(
            payload: [
                'avatar' => [
                    'filename' => 'person-avatar.png',
                    'content_url' => 'http://localhost:8000/person-avatar.png',
                ],
            ],
            mediaFieldContracts: [
                'avatar' => [
                    'type' => 'file',
                    'max_file_size_kb' => 512,
                ],
            ],
        );

        $this->fail('Expected ValidationException to be thrown.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['avatar'][0] ?? null)
            ->toContain('host is not allowed');

        Http::assertNothingSent();
    }
});

it('rejects content_url values that include user credentials', function () {
    Http::fake();

    $normalizer = app(McpFilePayloadNormalizer::class);

    try {
        $normalizer->normalize(
            payload: [
                'avatar' => [
                    'filename' => 'person-avatar.png',
                    'content_url' => 'https://user:secret@example.com/uploads/person-avatar.png',
                ],
            ],
            mediaFieldContracts: [
                'avatar' => [
                    'type' => 'file',
                    'max_file_size_kb' => 512,
                ],
            ],
        );

        $this->fail('Expected ValidationException to be thrown.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['avatar'][0] ?? null)
            ->toContain('must not include user credentials');

        Http::assertNothingSent();
    }
});

it('rejects content_url values that redirect', function () {
    Http::fake([
        'https://example.com/uploads/person-avatar.png' => Http::response('', 302, [
            'Location' => 'https://cdn.example.com/uploads/person-avatar.png',
        ]),
    ]);

    $normalizer = app(McpFilePayloadNormalizer::class);

    try {
        $normalizer->normalize(
            payload: [
                'avatar' => [
                    'filename' => 'person-avatar.png',
                    'content_url' => 'https://example.com/uploads/person-avatar.png',
                ],
            ],
            mediaFieldContracts: [
                'avatar' => [
                    'type' => 'file',
                    'max_file_size_kb' => 512,
                ],
            ],
        );

        $this->fail('Expected ValidationException to be thrown.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['avatar'][0] ?? null)
            ->toContain('must not redirect');
    }
});

it('returns a clear MCP-specific error when descriptor has neither base64 nor URL content', function () {
    $normalizer = app(McpFilePayloadNormalizer::class);

    try {
        $normalizer->normalize(
            payload: [
                'avatar' => [
                    'filename' => 'person-avatar.png',
                ],
            ],
            mediaFieldContracts: [
                'avatar' => [
                    'type' => 'file',
                    'max_file_size_kb' => 512,
                ],
            ],
        );

        $this->fail('Expected ValidationException to be thrown.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['avatar'][0] ?? null)
            ->toContain('content_base64')
            ->toContain('content_url')
            ->toContain('download_url')
            ->toContain('multipart/form-data');
    }
});
