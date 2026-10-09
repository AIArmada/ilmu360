<?php

use App\Support\ShareCards\ShareCardFonts;

if (! function_exists('shareCardFontsDir')) {
    function shareCardFontsDir(): string
    {
        return dirname(__DIR__, 3).'/public/fonts/share-cards';
    }
}

if (! function_exists('shareCardMakeTempDir')) {
    function shareCardMakeTempDir(): string
    {
        $directory = rtrim(sys_get_temp_dir(), '/').'/share-card-fonts-test-'.bin2hex(random_bytes(8));

        mkdir($directory, 0700, true);

        return $directory;
    }
}

if (! function_exists('shareCardRemoveDir')) {
    function shareCardRemoveDir(string $directory): void
    {
        $entries = scandir($directory);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                shareCardRemoveDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}

it('pins the exact supported locale list', function (): void {
    expect(ShareCardFonts::SUPPORTED_LOCALES)->toBe(['ms', 'id', 'en', 'ar', 'zh', 'ta', 'jv'])
        ->and(ShareCardFonts::isSupportedLocale('ms'))->toBeTrue()
        ->and(ShareCardFonts::isSupportedLocale('jv'))->toBeTrue()
        ->and(ShareCardFonts::isSupportedLocale('xx'))->toBeFalse()
        ->and(ShareCardFonts::isSupportedLocale(''))->toBeFalse();
});

it('builds a primary-first fallback chain per locale', function (): void {
    expect(ShareCardFonts::fontDescription('ar', 44, true))
        ->toBe('Noto Sans Arabic, Noto Sans, Noto Sans SC, Noto Sans Tamil, Noto Sans Javanese Bold 44')
        ->and(ShareCardFonts::fontDescription('ms', 30, false))
        ->toBe('Noto Sans, Noto Sans Arabic, Noto Sans SC, Noto Sans Tamil, Noto Sans Javanese 30')
        ->and(ShareCardFonts::fontDescription('jv', 50, true))->toStartWith('Noto Sans, ')
        ->and(ShareCardFonts::fontDescription('jv', 50, true))->toContain('Noto Sans Javanese');
});

it('rejects font descriptions for unsupported locales', function (): void {
    ShareCardFonts::fontDescription('xx', 44, true);
})->throws(InvalidArgumentException::class);

it('derives a stable revision token from manifest bytes', function (): void {
    $bytes = '{"fonts":[]}';

    expect(ShareCardFonts::manifestRevision($bytes))->toMatch('/^fonts-[0-9a-f]{16}$/')
        ->and(ShareCardFonts::manifestRevision($bytes))->toBe(ShareCardFonts::manifestRevision($bytes))
        ->and(ShareCardFonts::manifestRevision($bytes.' '))->not->toBe(ShareCardFonts::manifestRevision($bytes));
});

it('verifies the real vendored asset directory', function (): void {
    $verification = (new ShareCardFonts(shareCardFontsDir()))->verify();
    $families = array_keys($verification['families']);
    sort($families);
    $expected = ShareCardFonts::ALL_FAMILIES;
    sort($expected);

    expect($verification['ok'])->toBeTrue()
        ->and($verification['revision'])->toMatch('/^fonts-[0-9a-f]{16}$/')
        ->and($families)->toBe($expected)
        ->and($verification['missing'])->toBe([])
        ->and(implode("\n", $verification['notes']))->toContain('system fallback disabled');

    foreach ($verification['families'] as $path) {
        expect(is_file($path))->toBeTrue();
    }
});

it('fails verification for a missing directory or manifest', function (): void {
    $missingDir = (new ShareCardFonts(shareCardFontsDir().'-does-not-exist'))->verify();

    expect($missingDir['ok'])->toBeFalse()
        ->and($missingDir['revision'])->toBe('unverified');

    $empty = shareCardMakeTempDir();

    try {
        $noManifest = (new ShareCardFonts($empty))->verify();

        expect($noManifest['ok'])->toBeFalse()
            ->and(implode("\n", $noManifest['missing']))->toContain('manifest');
    } finally {
        shareCardRemoveDir($empty);
    }
});

it('fails verification for malformed manifests and size mismatches', function (): void {
    $directory = shareCardMakeTempDir();

    try {
        file_put_contents($directory.'/fonts.json', '{oops');

        expect((new ShareCardFonts($directory))->verify()['ok'])->toBeFalse();

        file_put_contents($directory.'/x.ttf', 'abc');
        file_put_contents($directory.'/fonts.json', json_encode([
            'fonts' => [['family' => 'X', 'file' => 'x.ttf', 'bytes' => 5, 'sha256' => hash('sha256', 'abc')]],
        ]));

        $mismatch = (new ShareCardFonts($directory))->verify();

        expect($mismatch['ok'])->toBeFalse()
            ->and(implode("\n", $mismatch['missing']))->toContain('size mismatch');
    } finally {
        shareCardRemoveDir($directory);
    }
});

it('forbids unlisted font files in the asset directory', function (): void {
    $directory = shareCardMakeTempDir();

    try {
        file_put_contents($directory.'/listed.ttf', 'listed-bytes');
        file_put_contents($directory.'/evil.ttf', 'evil-bytes');
        file_put_contents($directory.'/fonts.json', json_encode([
            'fonts' => [[
                'family' => 'Listed',
                'file' => 'listed.ttf',
                'bytes' => strlen('listed-bytes'),
                'sha256' => hash('sha256', 'listed-bytes'),
            ]],
        ]));

        $verification = (new ShareCardFonts($directory))->verify();

        expect($verification['ok'])->toBeFalse()
            ->and(implode("\n", $verification['missing']))->toContain('Unlisted font file [evil.ttf]');
    } finally {
        shareCardRemoveDir($directory);
    }
});
