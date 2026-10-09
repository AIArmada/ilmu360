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

it('rejects undecodable or blank required labels without echoing input', function (string $field, string $value, bool $withMarker): void {
    // The marker proves error text never echoes caller input; it is only
    // appended to undecodable values, never to blank ones (which would stop
    // being blank).
    $value = $withMarker ? $value.' MARKER-should-never-echo-'.$field : $value;

    $input = new ShareCardRenderInput(
        title: $field === 'title' ? $value : 'Kuliah Maghrib',
        locale: 'ms',
        brandLine: $field === 'brandLine' ? $value : 'ilmu360°',
        scheduleLabel: $field === 'scheduleLabel' ? $value : 'Sabtu · 7:30 malam',
    );

    $result = shareCardRenderer()->render($input);

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toBe(ShareCardError::InvalidInput)
        ->and($result->pngBytes)->toBeNull()
        ->and($result->width)->toBe(1200)
        ->and($result->height)->toBe(630)
        ->and($result->rendererRevision)->toBe('scr-001')
        ->and($result->errorMessage)->not->toContain('MARKER-should-never-echo');
})->with([
    'undecodable title' => ['title', "\xFF\xFE", true],
    'blank title' => ['title', '   ', false],
    'undecodable brand' => ['brandLine', "\xFF\xFE", true],
    'blank brand' => ['brandLine', '', false],
    'undecodable schedule' => ['scheduleLabel', "\xFF\xFE", true],
    'blank schedule' => ['scheduleLabel', "\t\n ", false],
]);

it('rejects undecodable optional labels but omits blank ones', function (): void {
    $renderer = shareCardRenderer();

    $bad = $renderer->render(new ShareCardRenderInput(
        title: 'Kuliah Maghrib',
        locale: 'ms',
        brandLine: 'ilmu360°',
        scheduleLabel: 'Sabtu · 7:30 malam',
        venueLabel: "\xFF\xFE",
    ));

    $blank = $renderer->render(new ShareCardRenderInput(
        title: 'Kuliah Maghrib',
        locale: 'ms',
        brandLine: 'ilmu360°',
        scheduleLabel: 'Sabtu · 7:30 malam',
        venueLabel: '   ',
        speakerLabel: null,
    ));

    expect($bad->ok)->toBeFalse()
        ->and($bad->error)->toBe(ShareCardError::InvalidInput)
        ->and($blank->ok)->toBeTrue();
});

it('rejects unsupported locales explicitly', function (string $locale): void {
    $result = shareCardRenderer()->render(new ShareCardRenderInput(
        title: 'Kuliah Maghrib',
        locale: $locale,
        brandLine: 'ilmu360°',
        scheduleLabel: 'Sabtu · 7:30 malam',
    ));

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toBe(ShareCardError::UnsupportedLocale)
        ->and($result->pngBytes)->toBeNull()
        ->and($result->errorMessage)->not->toContain('MARKER-should-never-echo');
})->with([
    'unknown code' => ['xx'],
    'empty code' => [''],
    'region variant' => ['ms-MY'],
    'marker is never echoed' => ['xx-MARKER-should-never-echo'],
]);

it('rejects unbounded, foreign-format or degenerate raster bytes', function (string $bytes): void {
    $result = shareCardRenderer()->render(new ShareCardRenderInput(
        title: 'Kuliah Maghrib',
        locale: 'ms',
        brandLine: 'ilmu360°',
        scheduleLabel: 'Sabtu · 7:30 malam',
        coverImageBytes: $bytes,
    ));

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toBe(ShareCardError::RasterRejected)
        ->and($result->pngBytes)->toBeNull();
})->with(static function (): array {
    $gif = static function (): string {
        $image = imagecreatetruecolor(64, 64);

        ob_start();
        imagegif($image);

        return (string) ob_get_clean();
    };

    return [
        'oversize bytes' => [str_repeat('x', 2000001)],
        'non-image bytes' => ['this is not an image'],
        'truncated png' => [substr(shareCardSolidRaster(64, 64, 'png'), 0, 40)],
        'gif is outside the safe-format policy' => [$gif()],
        'below minimum dimensions' => [shareCardSolidRaster(4, 4, 'png')],
        'degenerate aspect exceeds the upscale budget' => [shareCardSolidRaster(8, 4096, 'png')],
    ];
});

it('treats shell, markup and path payloads as literal text', function (): void {
    $renderer = shareCardRenderer();

    $input = new ShareCardRenderInput(
        title: '../fonts.conf /etc/passwd --font=Evil <span foreground="red">x</span> $(touch /tmp/pwn) `id` | cat %n',
        locale: 'ms',
        brandLine: 'ilmu360°',
        scheduleLabel: 'Sabtu · 7:30 malam',
    );

    $first = $renderer->render($input);
    $second = $renderer->render($input);

    expect($first->ok)->toBeTrue()
        ->and($second->ok)->toBeTrue()
        ->and($first->pngBytes)->toBe($second->pngBytes)
        ->and(file_exists('/tmp/pwn'))->toBeFalse();
});

it('renders control-heavy titles after sanitization', function (): void {
    $result = shareCardRenderer()->render(new ShareCardRenderInput(
        title: "Kuliah\x00Maghrib\x07 \u{202E}Perdana\nSiri\t5",
        locale: 'ms',
        brandLine: 'ilmu360°',
        scheduleLabel: 'Sabtu · 7:30 malam',
    ));

    expect($result->ok)->toBeTrue();
});

it('reports missing fonts and shapers as data, never exceptions', function (): void {
    $noFonts = shareCardRenderer(fontDirectory: shareCardFontsDir().'-does-not-exist');
    $noFontsReadiness = $noFonts->readiness();
    $noFontsRender = $noFonts->render(new ShareCardRenderInput(
        title: 'Kuliah Maghrib',
        locale: 'ms',
        brandLine: 'ilmu360°',
        scheduleLabel: 'Sabtu · 7:30 malam',
    ));

    expect($noFontsReadiness->ready)->toBeFalse()
        ->and($noFontsRender->ok)->toBeFalse()
        ->and($noFontsRender->error)->toBe(ShareCardError::FontsUnavailable);

    $noPango = shareCardRenderer(pangoBinary: '/nonexistent/pango-view');
    $noPangoReadiness = $noPango->readiness();
    $noPangoRender = $noPango->render(new ShareCardRenderInput(
        title: 'Kuliah Maghrib',
        locale: 'ms',
        brandLine: 'ilmu360°',
        scheduleLabel: 'Sabtu · 7:30 malam',
    ));

    expect($noPangoReadiness->ready)->toBeFalse()
        ->and($noPangoRender->ok)->toBeFalse()
        ->and($noPangoRender->error)->toBe(ShareCardError::ShaperUnavailable);

    $badWork = shareCardRenderer(workDirectory: '/nonexistent-share-card-workdir');
    $badWorkRender = $badWork->render(new ShareCardRenderInput(
        title: 'Kuliah Maghrib',
        locale: 'ms',
        brandLine: 'ilmu360°',
        scheduleLabel: 'Sabtu · 7:30 malam',
    ));

    expect($badWorkRender->ok)->toBeFalse()
        ->and($badWorkRender->error)->toBe(ShareCardError::RenderFailed);
});

it('maps shaper subprocess failures to explicit render errors', function (string $binary): void {
    $renderer = shareCardRenderer(pangoBinary: $binary);

    expect($renderer->readiness()->ready)->toBeFalse();

    $result = $renderer->render(new ShareCardRenderInput(
        title: 'Kuliah Maghrib',
        locale: 'ms',
        brandLine: 'ilmu360°',
        scheduleLabel: 'Sabtu · 7:30 malam',
    ));

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toBe(ShareCardError::RenderFailed)
        ->and($result->pngBytes)->toBeNull();
})->with([
    'false exits nonzero' => ['/usr/bin/false'],
    'true produces no strip' => ['/usr/bin/true'],
]);

it('maps shaper overruns to an explicit timeout error', function (): void {
    $script = rtrim(sys_get_temp_dir(), '/').'/share-card-sleep-'.bin2hex(random_bytes(8)).'.sh';

    file_put_contents($script, "#!/bin/sh\nexec /bin/sleep 30\n");
    chmod($script, 0755);

    try {
        $result = shareCardRenderer(pangoBinary: $script, timeout: 0.3)->render(new ShareCardRenderInput(
            title: 'Kuliah Maghrib',
            locale: 'ms',
            brandLine: 'ilmu360°',
            scheduleLabel: 'Sabtu · 7:30 malam',
        ));

        expect($result->ok)->toBeFalse()
            ->and($result->error)->toBe(ShareCardError::TimedOut)
            ->and($result->pngBytes)->toBeNull();
    } finally {
        unlink($script);
    }
});
