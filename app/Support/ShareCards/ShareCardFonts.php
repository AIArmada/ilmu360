<?php

declare(strict_types=1);

namespace App\Support\ShareCards;

use InvalidArgumentException;

/**
 * Version-pinned font catalog for the share-card renderer leaf.
 *
 * Separates the runtime renderability check from the full asset integrity
 * audit. Rendering checks its manifest and font files without rehashing large
 * binaries; verify() hashes fonts and license files for asset verification.
 */
final class ShareCardFonts
{
    public const MANIFEST_FILE = 'fonts.json';

    public const SUPPORTED_LOCALES = ['ms', 'id', 'en', 'ar', 'zh', 'ta', 'jv'];

    /**
     * Canonical fallback order. The per-locale primary family moves first;
     * every other family follows so the shaper resolves mixed-script runs
     * per character instead of assuming one script per locale.
     */
    public const ALL_FAMILIES = [
        'Noto Sans',
        'Noto Sans Arabic',
        'Noto Sans SC',
        'Noto Sans Tamil',
        'Noto Sans Javanese',
    ];

    private const PRIMARY_FAMILY = [
        'ms' => 'Noto Sans',
        'id' => 'Noto Sans',
        'en' => 'Noto Sans',
        'ar' => 'Noto Sans Arabic',
        'zh' => 'Noto Sans SC',
        'ta' => 'Noto Sans Tamil',
        'jv' => 'Noto Sans',
    ];

    private const FONT_EXTENSIONS = ['ttf', 'otf', 'ttc', 'woff', 'woff2'];

    public function __construct(private readonly string $fontDirectory) {}

    public function fontDirectory(): string
    {
        return $this->fontDirectory;
    }

    public static function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    /**
     * Pango font description with a primary-first fallback chain, e.g.
     * "Noto Sans Arabic, Noto Sans, ... Bold 44". Weight and size apply to
     * the whole description; Pango matches each fallback family per run.
     *
     * @throws InvalidArgumentException When the locale is not supported.
     */
    public static function fontDescription(string $locale, int $sizePx, bool $bold): string
    {
        if (! isset(self::PRIMARY_FAMILY[$locale])) {
            throw new InvalidArgumentException("Unsupported share-card locale [{$locale}].");
        }

        $primary = self::PRIMARY_FAMILY[$locale];
        $families = [$primary];

        foreach (self::ALL_FAMILIES as $family) {
            if ($family !== $primary) {
                $families[] = $family;
            }
        }

        return implode(', ', $families).($bold ? ' Bold ' : ' ').$sizePx;
    }

    /**
     * Cheap revision token over the manifest bytes. Callers use it after the
     * relevant renderability or full-integrity check succeeds.
     */
    public static function manifestRevision(string $manifestBytes): string
    {
        return 'fonts-'.substr(hash('sha256', $manifestBytes), 0, 16);
    }

    /**
     * Full verification: manifest shape, file presence, byte sizes, sha256 of
     * every font and license, and absence of unlisted font files.
     *
     * @return array{ok: bool, revision: string, families: array<string, string>, missing: list<string>, notes: list<string>}
     */
    public function verify(): array
    {
        $verification = $this->verifyIntegrity();
        $missing = $verification['missing'];

        foreach (self::ALL_FAMILIES as $family) {
            if (! isset($verification['families'][$family])) {
                $missing[] = "Font family [{$family}] is not covered by a verified file.";
            }
        }

        if ($verification['ok'] && $missing === []) {
            return $verification;
        }

        return [
            ...$verification,
            'ok' => false,
            'revision' => 'unverified',
            'missing' => $missing,
        ];
    }

    /**
     * Checks the files the renderer consumes without hashing assets on every render.
     *
     * @return array{ok: bool, revision: string, families: array<string, string>, missing: list<string>, notes: list<string>}
     */
    public function verifyRenderable(): array
    {
        $missing = [];
        $families = [];
        $directory = rtrim($this->fontDirectory, '/');

        if ($directory === '' || ! is_dir($directory)) {
            return [
                'ok' => false,
                'revision' => 'unverified',
                'families' => [],
                'missing' => ["Font directory [{$this->fontDirectory}] is not readable."],
                'notes' => [],
            ];
        }

        $manifestPath = $directory.'/'.self::MANIFEST_FILE;
        $manifestBytes = is_file($manifestPath) ? file_get_contents($manifestPath) : false;

        if ($manifestBytes === false || $manifestBytes === '') {
            return [
                'ok' => false,
                'revision' => 'unverified',
                'families' => [],
                'missing' => ["Font manifest [{$manifestPath}] is missing or unreadable."],
                'notes' => [],
            ];
        }

        /** @var mixed $manifest */
        $manifest = json_decode($manifestBytes, true);

        if (! is_array($manifest) || ! isset($manifest['fonts']) || ! is_array($manifest['fonts']) || $manifest['fonts'] === []) {
            return [
                'ok' => false,
                'revision' => 'unverified',
                'families' => [],
                'missing' => ["Font manifest [{$manifestPath}] is not valid JSON with a fonts list."],
                'notes' => [],
            ];
        }

        /** @var array<int, mixed> $fonts */
        $fonts = $manifest['fonts'];
        $listedFiles = [self::MANIFEST_FILE];

        foreach ($fonts as $index => $entry) {
            if (! is_array($entry)
                || ! isset($entry['family'], $entry['file'])
                || ! is_string($entry['family']) || ! is_string($entry['file'])) {
                $missing[] = "Font manifest entry [{$index}] is malformed.";

                continue;
            }

            $listedFiles[] = $entry['file'];
            $path = $directory.'/'.$entry['file'];

            if (! is_file($path)) {
                $missing[] = "Font file [{$entry['file']}] is missing.";

                continue;
            }

            $families[$entry['family']] = $path;
        }

        foreach ($this->unlistedFontFiles($directory, $listedFiles) as $extra) {
            $missing[] = "Unlisted font file [{$extra}] is forbidden in the asset directory.";
        }

        if ($families === []) {
            $missing[] = 'No usable font files are listed in the manifest.';
        }

        $ok = $missing === [];
        $notes = $ok ? [count($families).' available families, system fallback disabled.'] : [];

        return [
            'ok' => $ok,
            'revision' => $ok ? self::manifestRevision($manifestBytes) : 'unverified',
            'families' => $families,
            'missing' => $missing,
            'notes' => $notes,
        ];
    }

    /**
     * Verifies present font files and all license files without requiring every font family.
     *
     * @return array{ok: bool, revision: string, families: array<string, string>, missing: list<string>, notes: list<string>}
     */
    public function verifyIntegrity(): array
    {
        $missing = [];
        $notes = [];
        $families = [];
        $integrityFailed = false;
        $directory = rtrim($this->fontDirectory, '/');

        if ($directory === '' || ! is_dir($directory)) {
            return [
                'ok' => false,
                'revision' => 'unverified',
                'families' => [],
                'missing' => ["Font directory [{$this->fontDirectory}] is not readable."],
                'notes' => [],
            ];
        }

        $manifestPath = $directory.'/'.self::MANIFEST_FILE;
        $manifestBytes = is_file($manifestPath) ? file_get_contents($manifestPath) : false;

        if ($manifestBytes === false || $manifestBytes === '') {
            $missing[] = "Font manifest [{$manifestPath}] is missing or unreadable.";

            return ['ok' => false, 'revision' => 'unverified', 'families' => [], 'missing' => $missing, 'notes' => []];
        }

        /** @var mixed $manifest */
        $manifest = json_decode($manifestBytes, true);

        if (! is_array($manifest) || ! isset($manifest['fonts']) || ! is_array($manifest['fonts'])) {
            $missing[] = "Font manifest [{$manifestPath}] is not valid JSON with a fonts list.";

            return ['ok' => false, 'revision' => 'unverified', 'families' => [], 'missing' => $missing, 'notes' => []];
        }

        /** @var array<int, mixed> $fonts */
        $fonts = $manifest['fonts'];
        $listedFiles = [self::MANIFEST_FILE];

        foreach ($fonts as $index => $entry) {
            if (! is_array($entry)
                || ! isset($entry['family'], $entry['file'], $entry['bytes'], $entry['sha256'])
                || ! is_string($entry['family']) || ! is_string($entry['file'])
                || ! is_int($entry['bytes']) || ! is_string($entry['sha256'])) {
                $missing[] = "Font manifest entry [{$index}] is malformed.";
                $integrityFailed = true;

                continue;
            }

            /** @var array{family: string, file: string, bytes: int, sha256: string} $entry */
            $listedFiles[] = $entry['file'];
            $path = $directory.'/'.$entry['file'];

            if (! is_file($path)) {
                $missing[] = "Font file [{$entry['file']}] is missing.";
                $integrityFailed = true;

                continue;
            }

            $size = filesize($path);

            if ($size !== $entry['bytes']) {
                $missing[] = "Font file [{$entry['file']}] size mismatch.";
                $integrityFailed = true;

                continue;
            }

            $hash = hash_file('sha256', $path);

            if ($hash === false || ! hash_equals($entry['sha256'], $hash)) {
                $missing[] = "Font file [{$entry['file']}] sha256 mismatch.";
                $integrityFailed = true;

                continue;
            }

            $families[$entry['family']] = $path;
        }

        /** @var mixed $licenses */
        $licenses = $manifest['licenses'] ?? null;

        if (! is_array($licenses) || $licenses === []) {
            $missing[] = 'Font manifest license list is missing or malformed.';
            $integrityFailed = true;
        } else {
            foreach ($licenses as $index => $entry) {
                if (! is_array($entry)
                    || ! isset($entry['file'], $entry['bytes'], $entry['sha256'])
                    || ! is_string($entry['file']) || ! is_int($entry['bytes']) || ! is_string($entry['sha256'])) {
                    $missing[] = "Font manifest license entry [{$index}] is malformed.";
                    $integrityFailed = true;

                    continue;
                }

                /** @var array{file: string, bytes: int, sha256: string} $entry */
                $listedFiles[] = $entry['file'];
                $path = $directory.'/'.$entry['file'];

                if (! is_file($path)) {
                    $missing[] = "License file [{$entry['file']}] is missing.";
                    $integrityFailed = true;

                    continue;
                }

                if (filesize($path) !== $entry['bytes']) {
                    $missing[] = "License file [{$entry['file']}] size mismatch.";
                    $integrityFailed = true;

                    continue;
                }

                $hash = hash_file('sha256', $path);

                if ($hash === false || ! hash_equals($entry['sha256'], $hash)) {
                    $missing[] = "License file [{$entry['file']}] sha256 mismatch.";
                    $integrityFailed = true;
                }
            }
        }

        $unlistedFontFiles = $this->unlistedFontFiles($directory, $listedFiles);

        foreach ($unlistedFontFiles as $extra) {
            $missing[] = "Unlisted font file [{$extra}] is forbidden in the asset directory.";
        }

        if ($unlistedFontFiles !== []) {
            $integrityFailed = true;
        }

        if (isset($manifest['upstream_commit']) && is_string($manifest['upstream_commit'])) {
            $notes[] = 'upstream google/fonts@'.substr($manifest['upstream_commit'], 0, 12);
        }

        $notes[] = count($families).' verified families, system fallback disabled.';

        return [
            'ok' => ! $integrityFailed,
            'revision' => ! $integrityFailed ? self::manifestRevision($manifestBytes) : 'unverified',
            'families' => $families,
            'missing' => $missing,
            'notes' => $notes,
        ];
    }

    /**
     * @param  list<string>  $listedFiles
     * @return list<string>
     */
    private function unlistedFontFiles(string $directory, array $listedFiles): array
    {
        $entries = scandir($directory);

        if ($entries === false) {
            return [];
        }

        $listed = array_flip($listedFiles);
        $extra = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || isset($listed[$entry])) {
                continue;
            }

            if (! is_file($directory.'/'.$entry)) {
                continue;
            }

            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));

            if (in_array($extension, self::FONT_EXTENSIONS, true)) {
                $extra[] = $entry;
            }
        }

        sort($extra);

        return $extra;
    }
}
