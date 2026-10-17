<?php

use App\Contracts\ShareCards\ShareCardError;
use App\Contracts\ShareCards\ShareCardRenderInput;
use App\Support\ShareCards\PangoShareCardRenderer;

if (! function_exists('shareCardFontsDir')) {
    function shareCardFontsDir(): string
    {
        return dirname(__DIR__, 3).'/public/fonts/share-cards';
    }
}

if (! function_exists('shareCardMatrixTitles')) {
    /** @return array<string, string> */
    function shareCardMatrixTitles(): array
    {
        return [
            'ms' => 'Kuliah Maghrib: Tafsir Surah Al-Kahfi',
            'en' => 'Weekly Halaqah: Stories of the Prophets',
            'id' => 'Pengajian Rutin: Kajian Kitab Kuning',
            'ar' => 'محاضرة: تفسير سورة الكهف ١٢٣',
            'zh' => '周五讲座：古兰经讲解',
            'ta' => 'வெள்ளி சொற்பொழிவு: திருக்குர்ஆன் விளக்கம்',
            'jv-latin' => 'Pengajian Malam Jumat: Tafsir Al-Kahfi',
            'jv-java' => 'ꦥꦼꦔꦗꦶꦪꦤ꧀ ꦩꦭꦼꦩ꧀ꦗꦸꦩꦸꦮꦃ',
            'jawi-1' => 'کولياه مڬرب: تفسير سورة الکهف',
            'jawi-2' => 'چراماه ڤردانا اڠکاس',
            'mixed' => 'Ceramah 中文: தமிழ் و ملايو ꦗꦮ',
        ];
    }
}

if (! function_exists('shareCardRenderer')) {
    function shareCardRenderer(?string $fontDirectory = null, ?string $pangoBinary = null, ?string $workDirectory = null, float $timeout = 8.0): PangoShareCardRenderer
    {
        return new PangoShareCardRenderer($fontDirectory ?? shareCardFontsDir(), $pangoBinary, $workDirectory, null, $timeout);
    }
}

if (! function_exists('shareCardSolidRaster')) {
    function shareCardSolidRaster(int $width, int $height, string $format): string
    {
        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, 30, 90, 80);
        imagefill($image, 0, 0, $color);

        ob_start();

        match ($format) {
            'png' => imagepng($image),
            'jpeg' => imagejpeg($image),
            'webp' => imagewebp($image),
            default => throw new InvalidArgumentException("Unsupported raster format [{$format}]."),
        };

        return (string) ob_get_clean();
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

if (! function_exists('shareCardPngChunkTypes')) {
    /** @return list<string> */
    function shareCardPngChunkTypes(string $bytes): array
    {
        $types = [];

        if (substr($bytes, 0, 8) !== "\x89PNG\r\n\x1A\n") {
            return $types;
        }

        $offset = 8;
        $length = strlen($bytes);

        while ($offset + 8 <= $length) {
            $dataLength = (ord($bytes[$offset]) << 24) | (ord($bytes[$offset + 1]) << 16) | (ord($bytes[$offset + 2]) << 8) | ord($bytes[$offset + 3]);
            $type = substr($bytes, $offset + 4, 4);
            $types[] = $type;
            $offset += 12 + $dataLength;

            if ($type === 'IEND' || $offset > $length) {
                break;
            }
        }

        return $types;
    }
}

it('reports full readiness on the installed runtime', function (): void {
    $readiness = shareCardRenderer()->readiness();

    expect($readiness->ready)->toBeTrue()
        ->and($readiness->missing)->toBe([])
        ->and($readiness->pangoBinary)->not->toBeNull()
        ->and(is_executable((string) $readiness->pangoBinary))->toBeTrue()
        ->and($readiness->pangoVersion)->toMatch('/^\d+\.\d+/')
        ->and($readiness->fontRevision)->toMatch('/^fonts-[0-9a-f]{16}$/')
        ->and(count($readiness->families))->toBe(5)
        ->and(implode("\n", $readiness->notes))->toContain('system fallback disabled');
});

it('renders a 1200x630 png with matching revision tokens', function (): void {
    $renderer = shareCardRenderer();
    $readiness = $renderer->readiness();

    $result = $renderer->render(new ShareCardRenderInput(
        title: shareCardMatrixTitles()['ms'],
        locale: 'ms',
        brandLine: 'ilmu360°',
        scheduleLabel: 'Sabtu, 10 Okt 2026 · 7:30 malam (MYT)',
        venueLabel: 'Masjid Wilayah Persekutuan, Kuala Lumpur',
        speakerLabel: 'Ustaz Ahmad Fadhli bin Yusof',
        verifiedLabel: 'Disahkan · Verified speaker',
    ));

    expect($result->ok)->toBeTrue()
        ->and($result->error)->toBeNull()
        ->and($result->width)->toBe(1200)
        ->and($result->height)->toBe(630)
        ->and($result->rendererRevision)->toBe('scr-001')
        ->and($result->fontRevision)->toBe($readiness->fontRevision)
        ->and($result->cacheRevision())->toBe('scr-001:'.$readiness->fontRevision);

    $size = getimagesizefromstring((string) $result->pngBytes);

    expect($size)->not->toBeFalse()
        ->and($size[0])->toBe(1200)
        ->and($size[1])->toBe(630)
        ->and($size['mime'])->toBe('image/png')
        ->and(substr((string) $result->pngBytes, 0, 8))->toBe("\x89PNG\r\n\x1A\n");
});

it('renders every supported locale deterministically', function (string $locale, string $title): void {
    $renderer = shareCardRenderer();

    $input = new ShareCardRenderInput(
        title: $title,
        locale: $locale,
        brandLine: 'ilmu360°',
        scheduleLabel: 'Sabtu · Saturday · السبت',
    );

    $first = $renderer->render($input);
    $second = $renderer->render($input);

    if (! $first->ok || ! $second->ok) {
        $this->fail(sprintf(
            'Locale [%s] render failed: %s %s / %s %s',
            $locale,
            $first->error->value,
            $first->errorMessage ?? '?',
            $second->error->value,
            $second->errorMessage ?? '?'
        ));
    }

    expect($first->pngBytes)->toBe($second->pngBytes)
        ->and(strlen((string) $first->pngBytes))->toBeGreaterThan(1000);
})->with([
    'malay' => ['ms', 'Kuliah Maghrib: Tafsir Surah Al-Kahfi'],
    'english' => ['en', 'Weekly Halaqah: Stories of the Prophets'],
    'indonesian' => ['id', 'Pengajian Rutin: Kajian Kitab Kuning'],
    'arabic with arabic-indic digits' => ['ar', 'محاضرة: تفسير سورة الكهف ١٢٣'],
    'chinese' => ['zh', '周五讲座：古兰经讲解'],
    'tamil conjuncts' => ['ta', 'வெள்ளி சொற்பொழிவு: திருக்குர்ஆன் விளக்கம்'],
    'javanese latin' => ['jv', 'Pengajian Malam Jumat: Tafsir Al-Kahfi'],
    'javanese hanacaraka' => ['jv', 'ꦥꦼꦔꦗꦶꦪꦤ꧀ ꦩꦭꦼꦩ꧀ꦗꦸꦩꦸꦮꦃ'],
]);

it('renders jawi titles carrying extension codepoints', function (string $title): void {
    $result = shareCardRenderer()->render(new ShareCardRenderInput(
        title: $title,
        locale: 'ms',
        brandLine: 'ilmu360°',
        scheduleLabel: 'Jumaat · 8:00 malam',
    ));

    expect($result->ok)->toBeTrue()
        ->and($result->width)->toBe(1200)
        ->and($result->height)->toBe(630);
})->with([
    'jawi with ga and keheh' => ['کولياه مڬرب: تفسير سورة الکهف'],
    'jawi with ca, pa and nga' => ['چراماه ڤردانا اڠکاس'],
]);

it('renders mixed-script titles and long titles without dropping them', function (): void {
    $renderer = shareCardRenderer();

    $mixed = $renderer->render(new ShareCardRenderInput(
        title: shareCardMatrixTitles()['mixed'],
        locale: 'ms',
        brandLine: 'ilmu360°',
        scheduleLabel: 'Ahad · Sunday · 星期日',
    ));

    $long = $renderer->render(new ShareCardRenderInput(
        title: str_repeat('Kuliah Maghrib Perdana Siri Berterusan dan Pengajian Berterusan ', 6),
        locale: 'ms',
        brandLine: 'ilmu360°',
        scheduleLabel: 'Setiap malam · Every night',
    ));

    expect($mixed->ok)->toBeTrue()
        ->and($long->ok)->toBeTrue()
        ->and($mixed->pngBytes)->not->toBe($long->pngBytes);
});

it('renders cancellation, reschedule and verified labels', function (string $status): void {
    $result = shareCardRenderer()->render(new ShareCardRenderInput(
        title: 'Kuliah Maghrib: Tafsir Surah Al-Kahfi',
        locale: 'ms',
        brandLine: 'ilmu360°',
        scheduleLabel: 'Sabtu, 10 Okt 2026 · 7:30 malam (MYT)',
        verifiedLabel: 'Disahkan · Verified speaker',
        statusLabel: $status,
    ));

    expect($result->ok)->toBeTrue();
})->with([
    'cancelled bilingual' => ['DIBATALKAN · CANCELLED'],
    'rescheduled with new date' => ['DITUNDA: Ahad, 18 Okt 2026 · POSTPONED'],
]);

it('normalizes locale case before shaping', function (): void {
    $result = shareCardRenderer()->render(new ShareCardRenderInput(
        title: 'Kuliah Maghrib',
        locale: 'MS',
        brandLine: 'ilmu360°',
        scheduleLabel: 'Sabtu · 7:30 malam',
    ));

    expect($result->ok)->toBeTrue();
});

it('paints trusted raster covers and keeps the honest placeholder otherwise', function (string $format): void {
    $renderer = shareCardRenderer();

    $withCover = $renderer->render(new ShareCardRenderInput(
        title: 'Kuliah Maghrib: Tafsir Surah Al-Kahfi',
        locale: 'ms',
        brandLine: 'ilmu360°',
        scheduleLabel: 'Sabtu · 7:30 malam',
        coverImageBytes: shareCardSolidRaster(400, 300, $format),
    ));

    $withoutCover = $renderer->render(new ShareCardRenderInput(
        title: 'Kuliah Maghrib: Tafsir Surah Al-Kahfi',
        locale: 'ms',
        brandLine: 'ilmu360°',
        scheduleLabel: 'Sabtu · 7:30 malam',
    ));

    expect($withCover->ok)->toBeTrue()
        ->and($withoutCover->ok)->toBeTrue()
        ->and($withCover->pngBytes)->not->toBe($withoutCover->pngBytes);
})->with([
    'png cover' => ['png'],
    'jpeg cover' => ['jpeg'],
    'webp cover' => ['webp'],
]);

it('emits exif-free png output with a minimal chunk set', function (): void {
    $result = shareCardRenderer()->render(new ShareCardRenderInput(
        title: 'Kuliah Maghrib: Tafsir Surah Al-Kahfi',
        locale: 'ms',
        brandLine: 'ilmu360°',
        scheduleLabel: 'Sabtu · 7:30 malam',
        coverImageBytes: shareCardSolidRaster(400, 300, 'jpeg'),
    ));

    expect($result->ok)->toBeTrue();

    $types = shareCardPngChunkTypes((string) $result->pngBytes);

    expect($types)->not->toBe([])
        ->and($types[0])->toBe('IHDR')
        ->and(end($types))->toBe('IEND')
        ->and($types)->not->toContain('eXIf')
        ->and($result->pngBytes)->not->toContain('Exif');
});

it('keeps per-user and private fields out of the render contract', function (): void {
    $parameters = array_map(
        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionClass(ShareCardRenderInput::class))->getConstructor()->getParameters()
    );

    expect($parameters)->toBe([
        'title',
        'locale',
        'brandLine',
        'scheduleLabel',
        'venueLabel',
        'speakerLabel',
        'verifiedLabel',
        'statusLabel',
        'coverImageBytes',
    ]);
});

it('forces the shaper onto the fontconfig backend with a hermetic environment', function (): void {
    $method = new ReflectionMethod(PangoShareCardRenderer::class, 'baseEnvironment');

    expect($method->invoke(shareCardRenderer(), '/tmp/run-dir-probe'))->toBe([
        'PATH' => '/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin',
        'LC_ALL' => 'C.UTF-8',
        'LANG' => 'C.UTF-8',
        'HOME' => '/tmp/run-dir-probe',
        'TMPDIR' => '/tmp/run-dir-probe',
        'PANGOCAIRO_BACKEND' => 'fc',
    ]);
});

it('shapes javanese from the vendored font, never system fallback', function (): void {
    $fontsDir = shareCardFontsDir();
    $partialDir = shareCardMakeTempDir();

    try {
        $manifest = json_decode((string) file_get_contents($fontsDir.'/fonts.json'), true);
        $kept = array_values(array_filter(
            $manifest['fonts'],
            static fn (array $entry): bool => $entry['family'] !== 'Noto Sans Javanese'
        ));

        expect($kept)->toHaveCount(4);

        foreach ($kept as $entry) {
            symlink($fontsDir.'/'.$entry['file'], $partialDir.'/'.$entry['file']);
        }

        foreach ($manifest['licenses'] as $entry) {
            symlink($fontsDir.'/'.$entry['file'], $partialDir.'/'.$entry['file']);
        }

        $manifest['fonts'] = $kept;
        file_put_contents($partialDir.'/fonts.json', json_encode($manifest));

        $javanese = new ShareCardRenderInput(
            title: 'ꦥꦼꦔꦗꦶꦪꦤ꧀ ꦩꦭꦼꦩ꧀ꦗꦸꦩꦸꦮꦃ',
            locale: 'jv',
            brandLine: 'ilmu360°',
            scheduleLabel: 'ꦗꦸꦩꦸꦮꦃ · 8:00',
        );

        $fullJavanese = shareCardRenderer()->render($javanese);
        $partialJavanese = shareCardRenderer(fontDirectory: $partialDir)->render($javanese);

        // Truthful boundary: the full set renders, while the Javanese-less
        // set refuses with a typed error instead of success-with-tofu. Both
        // outcomes still prove the render path reads the configured font set:
        // a CoreText/system-font regression would render both identically.
        expect($fullJavanese->ok)->toBeTrue()
            ->and($partialJavanese->ok)->toBeFalse()
            ->and($partialJavanese->error)->toBe(ShareCardError::UnsupportedText)
            ->and($partialJavanese->pngBytes)->toBeNull();

        // Control: a latin title must be byte-identical with and without the
        // javanese file present, proving the difference above is script
        // coverage rather than an experiment artifact (cache dirs, manifest
        // bytes, run directories).
        $latin = new ShareCardRenderInput(
            title: 'Kuliah Maghrib: Tafsir Surah Al-Kahfi',
            locale: 'ms',
            brandLine: 'ilmu360°',
            scheduleLabel: 'Sabtu · 7:30 malam',
        );

        $fullLatin = shareCardRenderer()->render($latin);
        $partialLatin = shareCardRenderer(fontDirectory: $partialDir)->render($latin);

        expect($fullLatin->ok)->toBeTrue()
            ->and($partialLatin->ok)->toBeTrue()
            ->and($fullLatin->pngBytes)->toBe($partialLatin->pngBytes);
    } finally {
        shareCardRemoveDir($partialDir);
    }
});

it('holds no static or global state between renders', function (): void {
    $input = new ShareCardRenderInput(
        title: 'Kuliah Maghrib: Tafsir Surah Al-Kahfi',
        locale: 'ms',
        brandLine: 'ilmu360°',
        scheduleLabel: 'Sabtu · 7:30 malam',
    );

    $first = shareCardRenderer()->render($input);
    $second = shareCardRenderer()->render($input);

    expect($first->ok)->toBeTrue()
        ->and($second->ok)->toBeTrue()
        ->and($first->pngBytes)->toBe($second->pngBytes)
        ->and((new ReflectionClass(PangoShareCardRenderer::class))->getStaticProperties())->toBe([]);
});
