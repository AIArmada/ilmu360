<?php

declare(strict_types=1);

namespace App\Host\Pango\ShareCards;

use App\Shared\Media\ShareCards\ShareCardError;
use App\Shared\Media\ShareCards\ShareCardReadiness;
use App\Shared\Media\ShareCards\ShareCardRenderer;
use App\Shared\Media\ShareCards\ShareCardRenderInput;
use App\Shared\Media\ShareCards\ShareCardRenderResult;
use App\Shared\Media\ShareCards\ShareCardRenderSpec;
use GdImage;
use Normalizer;
use Throwable;

/**
 * Deterministic 1200x630 share-card renderer over an isolated pango-view.
 *
 * Text strips are shaped by a bounded pango-view subprocess (argv array, no
 * shell, no markup, stdin from /dev/null) confined to the vendored fonts via
 * a per-render fontconfig file, then composited with GD onto a fixed brand
 * canvas. No network, no database, no global state: every call carries its
 * own temp directory and environment, so concurrent renders and long-lived
 * Octane workers cannot leak state into each other.
 *
 * Bump RENDERER_REVISION whenever geometry, palette, font descriptions,
 * strip budgets or the shaping pipeline change: the future cache key must
 * invalidate across renderer revisions.
 */
final readonly class PangoShareCardRenderer implements ShareCardRenderer
{
    public const int WIDTH = ShareCardRenderSpec::WIDTH;

    public const int HEIGHT = ShareCardRenderSpec::HEIGHT;

    public const string RENDERER_REVISION = ShareCardRenderSpec::RENDERER_REVISION;

    private const int MAX_RASTER_BYTES = 2000000;

    private const int MAX_RASTER_DIMENSION = 4096;

    private const int MIN_RASTER_DIMENSION = 8;

    private const int MAX_UPSCALE_PIXELS = 4000000;

    private const int MAX_STRIP_BYTES = 4000000;

    private const int MARGIN = 64;

    private const int COLUMN_WIDTH = 816;

    private const int RIBBON_HEIGHT = 84;

    private const int RIBBON_STRIP_WIDTH = 1072;

    private const int BRAND_SIZE = 30;

    private const int TITLE_SIZE = 62;

    private const int DENSE_TITLE_SIZE = 50;

    private const int SCHEDULE_SIZE = 35;

    private const int META_SIZE = 27;

    private const int STATUS_SIZE = 32;

    /** @var array{int, int, int} Deep emerald canvas (theme emerald-900 family). */
    private const array COLOR_BG = [23, 60, 52];

    /** @var array{int, int, int} */
    private const array COLOR_FOOTER = [249, 244, 236];

    /** @var array{int, int, int} */
    private const array COLOR_SCRIM = [9, 38, 33];

    /** @var array{int, int, int} Gold brand accents (theme gold family). */
    private const array COLOR_GOLD = [242, 200, 103];

    /** @var array{int, int, int} Cancellation/change ribbon. */
    private const array COLOR_RED = [127, 29, 29];

    /** @var list<string> */
    private const array PANGO_SEARCH_PATHS = [
        '/opt/homebrew/bin/pango-view',
        '/usr/local/bin/pango-view',
        '/usr/bin/pango-view',
    ];

    public function __construct(
        private string $fontDirectory,
        private ?string $pangoBinary = null,
        private ?string $workDirectory = null,
        private ?string $fontconfigCacheDirectory = null,
        private float $processTimeoutSeconds = 20.0,
    ) {}

    public function readiness(): ShareCardReadiness
    {
        try {
            return $this->checkReadiness();
        } catch (Throwable) {
            return new ShareCardReadiness(
                ready: false,
                fontDirectory: $this->fontDirectory,
                pangoBinary: null,
                pangoVersion: null,
                fontRevision: 'unverified',
                families: [],
                missing: ['Readiness check failed unexpectedly.'],
                notes: [],
            );
        }
    }

    public function render(ShareCardRenderInput $input): ShareCardRenderResult
    {
        try {
            return $this->doRender($input);
        } catch (Throwable) {
            return ShareCardRenderResult::failure(
                ShareCardError::RenderFailed,
                'Render failed unexpectedly.',
                self::WIDTH,
                self::HEIGHT,
                self::RENDERER_REVISION,
                'unverified',
            );
        }
    }

    private function checkReadiness(): ShareCardReadiness
    {
        $fonts = new ShareCardFonts($this->fontDirectory);
        $verification = $fonts->verifyRenderable();
        $missing = $verification['missing'];
        $notes = $verification['notes'];

        if (PHP_OS_FAMILY === 'Windows') {
            $missing[] = 'Windows is not supported (process and fontconfig confinement are unix-only).';
        }

        foreach ($this->missingExtensions() as $extension) {
            $missing[] = "PHP extension [{$extension}] is required.";
        }

        if (! function_exists('proc_open')) {
            $missing[] = 'proc_open() is required to run the bounded shaper subprocess.';
        }

        $binary = $this->resolvePangoBinary();
        $version = null;

        if ($binary === null) {
            $missing[] = 'pango-view binary was not found.';
        } else {
            $version = $this->pangoVersion($binary);

            if ($version === null) {
                $missing[] = "pango-view binary [{$binary}] did not report a usable version.";
            }
        }

        $workDirectory = $this->workDirectory ?? sys_get_temp_dir();

        if (! is_dir($workDirectory) || ! is_writable($workDirectory)) {
            $missing[] = "Work directory [{$workDirectory}] is not writable.";
        }

        return new ShareCardReadiness(
            ready: $missing === [],
            fontDirectory: $this->fontDirectory,
            pangoBinary: $binary,
            pangoVersion: $version,
            fontRevision: $verification['revision'],
            families: $verification['families'],
            missing: $missing,
            notes: $notes,
        );
    }

    /**
     * @return list<string>
     */
    private function missingExtensions(): array
    {
        $missing = [];

        if (! function_exists('imagecreatetruecolor')) {
            $missing[] = 'gd';
        }

        if (! class_exists(Normalizer::class)) {
            $missing[] = 'intl';
        }

        if (! function_exists('mb_strlen')) {
            $missing[] = 'mbstring';
        }

        return $missing;
    }

    private function doRender(ShareCardRenderInput $input): ShareCardRenderResult
    {
        $locale = strtolower(trim($input->locale));

        if (! ShareCardFonts::isSupportedLocale($locale)) {
            return $this->failure(ShareCardError::UnsupportedLocale, 'Render locale is not supported.');
        }

        $title = ShareCardText::sanitizeRequired($input->title, ShareCardText::TITLE_MAX_CHARS);
        $brand = ShareCardText::sanitizeRequired($input->brandLine, ShareCardText::BRAND_MAX_CHARS);
        $schedule = ShareCardText::sanitizeRequired($input->scheduleLabel, ShareCardText::LABEL_MAX_CHARS);
        $venue = ShareCardText::sanitizeOptional($input->venueLabel, ShareCardText::LABEL_MAX_CHARS);
        $speaker = ShareCardText::sanitizeOptional($input->speakerLabel, ShareCardText::LABEL_MAX_CHARS);
        $verified = ShareCardText::sanitizeOptional($input->verifiedLabel, ShareCardText::LABEL_MAX_CHARS);
        $status = ShareCardText::sanitizeOptional($input->statusLabel, ShareCardText::LABEL_MAX_CHARS);

        if ($title === null || $brand === null || $schedule === null
            || $venue === false || $speaker === false || $verified === false || $status === false) {
            return $this->failure(ShareCardError::InvalidInput, 'Card text is undecodable or a required label is blank.');
        }

        $raster = null;

        if ($input->coverImageBytes !== null) {
            $raster = $this->decodeRaster($input->coverImageBytes);

            if (! $raster instanceof GdImage) {
                return $this->failure(ShareCardError::RasterRejected, 'Cover bytes are not a bounded JPEG, PNG or WebP image.');
            }

            if ($this->coverFitExceedsBudget($raster)) {
                return $this->failure(ShareCardError::RasterRejected, 'Cover dimensions cannot cover-fit within budget.');
            }
        }

        $capability = $this->quickCapability();

        if ($capability['error'] !== null) {
            return $this->failure($capability['error'], $capability['message']);
        }

        $fontRevision = $capability['fontRevision'];

        // Truthful-render gate: every visible codepoint in every sanitized
        // strip must have a glyph in this render's own pinned font set.
        // Uncovered text refuses here, before any subprocess runs, so missing
        // glyphs can never return as success-with-tofu. The message carries
        // no caller text: the typed error is the whole signal.
        $missingGlyphs = ShareCardGlyphCoverage::missingVisibleCodepoints(
            $capability['fontFiles'],
            [$title, $brand, $schedule, $venue, $speaker, $verified, $status],
        );

        if ($missingGlyphs !== []) {
            return $this->failure(
                ShareCardError::UnsupportedText,
                'Card text contains characters the pinned fonts cannot render.',
                $fontRevision,
            );
        }

        $runDirectory = $this->createRunDirectory();

        if ($runDirectory === null) {
            return $this->failure(ShareCardError::RenderFailed, 'Work directory is not writable.');
        }

        $createdFiles = [];

        try {
            $confPath = $this->writeFontconfig($runDirectory, $capability['fontDirectory']);

            if ($confPath === null) {
                return $this->failure(ShareCardError::FontsUnavailable, 'Font directory is not usable.');
            }

            $createdFiles[] = $confPath;
            $direction = ShareCardText::baseDirection($title);
            $align = $direction === ShareCardText::DIRECTION_RTL ? 'right' : 'left';

            $metaCount = 0;

            foreach ([$venue, $speaker, $verified] as $label) {
                if ($label !== null) {
                    $metaCount++;
                }
            }

            // Deterministic capacity rule: dense cards (3+ extras beyond the
            // core brand/title/schedule) cap the title at 2 lines so every
            // supplied label fits without overlap; airier cards allow 3 lines.
            $extras = $metaCount + ($status !== null ? 1 : 0);
            $titleLines = $extras >= 3 ? 2 : 3;

            $strips = $this->renderStrips(
                $capability['binary'],
                $confPath,
                $runDirectory,
                $locale,
                $align,
                $brand,
                $title,
                $titleLines,
                $schedule,
                $venue,
                $speaker,
                $verified,
                $status,
                $createdFiles,
            );

            if ($strips === null) {
                return $this->timedOut($fontRevision);
            }

            if (isset($strips['error'])) {
                return $this->failure(ShareCardError::RenderFailed, 'Text shaping failed.', $fontRevision);
            }

            /** @var array<string, string> $stripPaths */
            $stripPaths = $strips['paths'];

            $pngBytes = $this->composite($stripPaths, $align === 'right', $raster);

            if ($pngBytes === null) {
                return $this->failure(ShareCardError::RenderFailed, 'Card content exceeds the fixed layout.', $fontRevision);
            }

            return ShareCardRenderResult::success(
                $pngBytes,
                self::WIDTH,
                self::HEIGHT,
                self::RENDERER_REVISION,
                $fontRevision,
            );
        } finally {
            $this->cleanupRunDirectory($runDirectory, $createdFiles);
        }
    }

    private function failure(
        ShareCardError $error,
        string $message,
        ?string $fontRevision = null,
    ): ShareCardRenderResult {
        return ShareCardRenderResult::failure(
            $error,
            $message,
            self::WIDTH,
            self::HEIGHT,
            self::RENDERER_REVISION,
            $fontRevision ?? 'unverified',
        );
    }

    private function timedOut(string $fontRevision): ShareCardRenderResult
    {
        return ShareCardRenderResult::failure(
            ShareCardError::TimedOut,
            'Text shaping exceeded the time budget.',
            self::WIDTH,
            self::HEIGHT,
            self::RENDERER_REVISION,
            $fontRevision,
        );
    }

    /**
     * Checks the same renderable font inputs as readiness without rehashing asset contents.
     *
     * @return array{error: ?ShareCardError, message: string, fontRevision: string, binary: string, fontDirectory: string, fontFiles: list<string>}
     */
    private function quickCapability(): array
    {
        $fail = (static fn (ShareCardError $error, string $message): array => ['error' => $error, 'message' => $message, 'fontRevision' => 'unverified', 'binary' => '', 'fontDirectory' => '', 'fontFiles' => []]);

        if (PHP_OS_FAMILY === 'Windows') {
            return $fail(ShareCardError::RenderFailed, 'Windows is not supported.');
        }

        $missingExtensions = $this->missingExtensions();

        if ($missingExtensions !== []) {
            return $fail(ShareCardError::RenderFailed, 'Missing PHP extension ['.$missingExtensions[0].'].');
        }

        if (! function_exists('proc_open')) {
            return $fail(ShareCardError::ShaperUnavailable, 'proc_open() is unavailable.');
        }

        $directory = rtrim($this->fontDirectory, '/');
        $verification = new ShareCardFonts($directory)->verifyRenderable();

        if (! $verification['ok'] || $verification['families'] === []) {
            return $fail(ShareCardError::FontsUnavailable, 'Pinned font manifest or font files are unavailable.');
        }

        $realDirectory = realpath($directory);

        if ($realDirectory === false) {
            return $fail(ShareCardError::FontsUnavailable, 'Font directory is not usable.');
        }

        $binary = $this->resolvePangoBinary();

        if ($binary === null) {
            return $fail(ShareCardError::ShaperUnavailable, 'pango-view binary was not found.');
        }

        $fontFiles = array_map(
            static fn (string $path): string => $realDirectory.'/'.substr($path, strlen($directory) + 1),
            array_values($verification['families']),
        );

        return [
            'error' => null,
            'message' => '',
            'fontRevision' => $verification['revision'],
            'binary' => $binary,
            'fontDirectory' => $realDirectory,
            'fontFiles' => $fontFiles,
        ];
    }

    private function resolvePangoBinary(): ?string
    {
        if ($this->pangoBinary !== null) {
            return is_file($this->pangoBinary) && is_executable($this->pangoBinary) ? $this->pangoBinary : null;
        }

        foreach (self::PANGO_SEARCH_PATHS as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        $path = getenv('PATH');

        if (! is_string($path) || $path === '') {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = rtrim($directory, '/').'/pango-view';

            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function pangoVersion(string $binary): ?string
    {
        $run = $this->runProcess([$binary, '--version'], $this->baseEnvironment(sys_get_temp_dir()), 5.0);

        if (! $run['ok']) {
            return null;
        }

        if (preg_match('/pango-view \(pango\) (\d+\.\d+(?:\.\d+)?)/', $run['output'], $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function createRunDirectory(): ?string
    {
        $workDirectory = $this->workDirectory ?? sys_get_temp_dir();

        if (! is_dir($workDirectory) || ! is_writable($workDirectory)) {
            return null;
        }

        $runDirectory = rtrim($workDirectory, '/').'/share-card-'.bin2hex(random_bytes(8));

        if (! @mkdir($runDirectory, 0700)) {
            return null;
        }

        return $runDirectory;
    }

    private function fontconfigCacheDirectory(string $workDirectory): ?string
    {
        $pid = getmypid();
        $cacheDirectory = $this->fontconfigCacheDirectory
            ?? rtrim($workDirectory, '/').'/share-card-fc-cache-'.($pid === false ? '0' : (string) $pid);

        if (is_dir($cacheDirectory)) {
            return is_writable($cacheDirectory) ? $cacheDirectory : null;
        }

        if (! @mkdir($cacheDirectory, 0700, true)) {
            return null;
        }

        return $cacheDirectory;
    }

    /**
     * Per-render fontconfig: pinned asset directory only, empty <config> so no
     * system fonts can leak in, and a process-scoped cache directory.
     */
    private function writeFontconfig(string $runDirectory, string $fontDirectory): ?string
    {
        $workDirectory = $this->workDirectory ?? sys_get_temp_dir();
        $cacheDirectory = $this->fontconfigCacheDirectory($workDirectory);

        if ($cacheDirectory === null) {
            return null;
        }

        $conf = '<?xml version="1.0"?>'."\n"
            .'<!DOCTYPE fontconfig SYSTEM "urn:fontconfig:fonts.dtd">'."\n"
            .'<fontconfig>'."\n"
            .'  <dir>'.htmlspecialchars($fontDirectory, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</dir>'."\n"
            .'  <cachedir>'.htmlspecialchars($cacheDirectory, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</cachedir>'."\n"
            .'  <config></config>'."\n"
            .'</fontconfig>'."\n";

        $confPath = $runDirectory.'/fonts.conf';

        if (file_put_contents($confPath, $conf) === false) {
            return null;
        }

        return $confPath;
    }

    /**
     * @param  list<string>  $createdFiles
     * @return array{paths: array<string, string>}|array{error: true}|null Null on timeout.
     */
    private function renderStrips(
        string $binary,
        string $confPath,
        string $runDirectory,
        string $locale,
        string $align,
        string $brand,
        string $title,
        int $titleLines,
        string $schedule,
        ?string $venue,
        ?string $speaker,
        ?string $verified,
        ?string $status,
        array &$createdFiles,
    ): ?array {
        $fontRegular = static fn (int $size): string => ShareCardFonts::fontDescription($locale, $size, false);
        $fontBold = static fn (int $size): string => ShareCardFonts::fontDescription($locale, $size, true);

        /** @var list<array{key: string, text: string, font: string, color: string, width: int, lines: int, align: string}> $specs */
        $specs = [
            ['key' => 'brand', 'text' => $brand, 'font' => $fontRegular(self::BRAND_SIZE), 'color' => '#F2C867', 'width' => self::COLUMN_WIDTH, 'lines' => 1, 'align' => $align],
            ['key' => 'title', 'text' => $title, 'font' => $fontBold($titleLines === 2 ? self::DENSE_TITLE_SIZE : self::TITLE_SIZE), 'color' => '#FFFFFF', 'width' => self::COLUMN_WIDTH, 'lines' => $titleLines, 'align' => $align],
            ['key' => 'schedule', 'text' => $schedule, 'font' => $fontBold(self::SCHEDULE_SIZE), 'color' => '#173C34', 'width' => self::RIBBON_STRIP_WIDTH, 'lines' => 1, 'align' => $align],
        ];

        foreach ([['venue', $venue], ['speaker', $speaker], ['verified', $verified]] as [$key, $label]) {
            if ($label !== null) {
                $specs[] = ['key' => $key, 'text' => $label, 'font' => $fontRegular(self::META_SIZE), 'color' => '#383F38', 'width' => self::RIBBON_STRIP_WIDTH, 'lines' => 1, 'align' => $align];
            }
        }

        if ($status !== null) {
            $specs[] = ['key' => 'status', 'text' => $status, 'font' => $fontBold(self::STATUS_SIZE), 'color' => '#FFFFFF', 'width' => self::RIBBON_STRIP_WIDTH, 'lines' => 1, 'align' => 'center'];
        }

        $env = $this->baseEnvironment($runDirectory);
        $env['FONTCONFIG_FILE'] = $confPath;
        $paths = [];

        foreach ($specs as $spec) {
            $outputPath = $runDirectory.'/strip-'.$spec['key'].'.png';

            $run = $this->runProcess([
                $binary,
                '-q',
                '--pixels',
                '--margin=0',
                '--width='.$spec['width'],
                '--wrap=word-char',
                '--height=-'.$spec['lines'],
                '--ellipsize=end',
                '--align='.$spec['align'],
                '--background=transparent',
                '--foreground='.$spec['color'],
                '--font='.$spec['font'],
                '--language='.$locale,
                '--text='.$spec['text'],
                '-o',
                $outputPath,
            ], $env, $this->processTimeoutSeconds);

            if (! $run['ok']) {
                return $run['timedOut'] ? null : ['error' => true];
            }

            if (! $this->isUsableStrip($outputPath)) {
                return ['error' => true];
            }

            $createdFiles[] = $outputPath;
            $paths[$spec['key']] = $outputPath;
        }

        return ['paths' => $paths];
    }

    /**
     * Hermetic subprocess environment: fixed PATH, fixed locale, temp and
     * HOME confinement. No caller or user data ever enters the environment.
     *
     * PANGOCAIRO_BACKEND=fc is load-bearing, not tuning: Homebrew Pango on
     * macOS defaults to the CoreText fontmap, which resolves fonts through
     * the macOS font registry and silently ignores FONTCONFIG_FILE. Without
     * this override every strip renders from Apple system fonts instead of
     * the vendored OFL assets (only observable on scripts the system lacks,
     * such as Javanese). On fontconfig-only builds the variable is a no-op.
     *
     * @return array<string, string>
     */
    private function baseEnvironment(string $runDirectory): array
    {
        return [
            'PATH' => '/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin',
            'LC_ALL' => 'C.UTF-8',
            'LANG' => 'C.UTF-8',
            'HOME' => $runDirectory,
            'TMPDIR' => $runDirectory,
            'PANGOCAIRO_BACKEND' => 'fc',
        ];
    }

    /**
     * Bounded subprocess execution over an argv array: PHP never invokes a
     * shell for array commands, so strip text cannot escape into commands.
     *
     * @param  list<string>  $argv
     * @param  array<string, string>  $env
     * @return array{ok: bool, timedOut: bool, output: string}
     */
    private function runProcess(array $argv, array $env, float $timeoutSeconds): array
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($argv, $descriptors, $pipes, null, $env);

        if (! is_resource($process)) {
            return ['ok' => false, 'timedOut' => false, 'output' => ''];
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                stream_set_blocking($pipe, false);
            }
        }

        $output = '';
        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = false;

        while (true) {
            foreach ($pipes as $pipe) {
                if (! is_resource($pipe)) {
                    continue;
                }

                $chunk = fread($pipe, 8192);

                if (is_string($chunk) && $chunk !== '') {
                    $room = 8192 - min(8192, strlen($output));

                    if ($room > 0) {
                        $output .= substr($chunk, 0, $room);
                    }
                }
            }

            $status = proc_get_status($process);

            if (! $status['running']) {
                $exitCode = (int) $status['exitcode'];

                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }

                proc_close($process);

                return ['ok' => $exitCode === 0, 'timedOut' => false, 'output' => $output];
            }

            if (microtime(true) > $deadline) {
                $timedOut = true;

                break;
            }

            $read = array_values(array_filter(
                $pipes,
                static fn (mixed $pipe): bool => is_resource($pipe) && ! feof($pipe),
            ));

            if ($read !== []) {
                $write = null;
                $except = null;
                stream_select($read, $write, $except, 0, 5000);
            }
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        proc_terminate($process);
        proc_close($process);

        return ['ok' => false, 'timedOut' => $timedOut, 'output' => $output];
    }

    private function isUsableStrip(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $size = filesize($path);

        if ($size === false || $size === 0 || $size > self::MAX_STRIP_BYTES) {
            return false;
        }

        $dimensions = getimagesize($path);

        if ($dimensions === false) {
            return false;
        }

        if ($dimensions[0] <= 0 || $dimensions[1] <= 0) {
            return false;
        }

        return $dimensions[0] <= self::WIDTH && $dimensions[1] <= self::HEIGHT;
    }

    /**
     * Decode trusted cover bytes into a truecolor GD image, or null when the
     * bytes violate the bounded safe-format policy. JPEG, PNG and WebP only;
     * transparency flattens onto the panel color at composite time.
     */
    private function decodeRaster(string $bytes): ?GdImage
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_RASTER_BYTES) {
            return null;
        }

        $info = getimagesizefromstring($bytes);

        if ($info === false) {
            return null;
        }

        if (! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return null;
        }

        if ($info[0] < self::MIN_RASTER_DIMENSION || $info[1] < self::MIN_RASTER_DIMENSION
            || $info[0] > self::MAX_RASTER_DIMENSION || $info[1] > self::MAX_RASTER_DIMENSION) {
            return null;
        }

        // Cover bytes are untrusted: GD warns on corrupt data, so the warning
        // is suppressed and decode failure maps to RasterRejected data.
        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            return null;
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return $image;
    }

    /**
     * Pre-compute the cover-fit upscale so degenerate aspect ratios fail as
     * RasterRejected up front instead of painting an empty panel.
     */
    private function coverFitExceedsBudget(GdImage $raster): bool
    {
        $srcW = imagesx($raster);
        $srcH = imagesy($raster);

        $scale = max(self::WIDTH / $srcW, self::HEIGHT / $srcH);

        return (int) ceil($srcW * $scale) * (int) ceil($srcH * $scale) > self::MAX_UPSCALE_PIXELS;
    }

    /**
     * @param  array<string, string>  $stripPaths
     */
    private function composite(array $stripPaths, bool $rightAligned, ?GdImage $raster): ?string
    {
        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

        if (! $canvas instanceof GdImage) {
            return null;
        }

        $bg = $this->allocate($canvas, self::COLOR_BG);
        $footer = $this->allocate($canvas, self::COLOR_FOOTER);
        $gold = $this->allocate($canvas, self::COLOR_GOLD);
        $red = $this->allocate($canvas, self::COLOR_RED);

        if ($bg === null || $footer === null || $gold === null || $red === null) {
            return null;
        }

        $strips = [];

        foreach ($stripPaths as $key => $path) {
            $strip = @imagecreatefrompng($path);

            if (! $strip instanceof GdImage) {
                return null;
            }

            $strips[$key] = $strip;
        }

        $details = array_intersect_key($strips, array_flip(['schedule', 'venue', 'speaker', 'verified']));
        $footerPadding = count($details) === 4 && isset($strips['status']) ? 16 : 22;
        $detailHeight = 2 * $footerPadding + 8 * (count($details) - 1);

        foreach ($details as $strip) {
            $detailHeight += imagesy($strip);
        }

        $ribbonTop = isset($strips['status']) ? self::HEIGHT - self::RIBBON_HEIGHT : self::HEIGHT;
        $footerTop = $ribbonTop - $detailHeight;
        $brandTop = 36;
        $titleAreaTop = $brandTop + imagesy($strips['brand']) + 28;
        $titleSpace = $footerTop - 32 - $titleAreaTop;
        $titleHeight = imagesy($strips['title']);

        if ($titleSpace <= 0) {
            return null;
        }

        imagefill($canvas, 0, 0, $bg);

        if ($raster instanceof GdImage) {
            $this->paintCoverFit($canvas, $raster, 0, 0, self::WIDTH, self::HEIGHT, $bg);
            $this->paintCoverScrim($canvas, $rightAligned, $footerTop);
        } else {
            $this->paintBrandMotif($canvas, $rightAligned, $footerTop);
        }

        $titleX = $rightAligned ? self::WIDTH - self::MARGIN - self::COLUMN_WIDTH : self::MARGIN;
        $this->copyStrip($canvas, $strips['brand'], $titleX, $brandTop);

        $title = $strips['title'];
        $fittedHeight = min($titleHeight, $titleSpace);
        $fittedWidth = max(1, intdiv(imagesx($title) * $fittedHeight, $titleHeight));
        $titleTop = $titleAreaTop + intdiv($titleSpace - $fittedHeight, 2);
        $fittedX = $rightAligned ? $titleX + imagesx($title) - $fittedWidth : $titleX;

        imagecopyresampled(
            $canvas,
            $title,
            $fittedX,
            $titleTop,
            0,
            0,
            $fittedWidth,
            $fittedHeight,
            imagesx($title),
            $titleHeight,
        );

        imagefilledrectangle($canvas, 0, $footerTop, self::WIDTH - 1, $ribbonTop - 1, $footer);
        $ruleX = $rightAligned ? self::WIDTH - self::MARGIN - 80 : self::MARGIN;
        imagefilledrectangle($canvas, $ruleX, $footerTop - 3, $ruleX + 79, $footerTop + 1, $gold);

        $detailTop = $footerTop + $footerPadding;

        foreach ($details as $strip) {
            $this->copyStrip($canvas, $strip, self::MARGIN, $detailTop);
            $detailTop += imagesy($strip) + 8;
        }

        if (isset($strips['status'])) {
            imagefilledrectangle($canvas, 0, $ribbonTop, self::WIDTH - 1, self::HEIGHT - 1, $red);
            $status = $strips['status'];
            $this->copyStrip(
                $canvas,
                $status,
                intdiv(self::WIDTH - imagesx($status), 2),
                $ribbonTop + intdiv(self::RIBBON_HEIGHT - imagesy($status), 2),
            );
        }

        ob_start();
        $encoded = imagepng($canvas);
        $bytes = ob_get_clean();

        return $encoded && is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    private function copyStrip(GdImage $canvas, GdImage $strip, int $x, int $y): void
    {
        imagecopy($canvas, $strip, $x, $y, 0, 0, imagesx($strip), imagesy($strip));
    }

    /**
     * @param  array{int, int, int}  $rgb
     */
    private function allocate(GdImage $canvas, array $rgb): ?int
    {
        $color = imagecolorallocate($canvas, $rgb[0], $rgb[1], $rgb[2]);

        return $color === false ? null : $color;
    }

    private function paintCoverScrim(GdImage $canvas, bool $rightAligned, int $height): void
    {
        [$red, $green, $blue] = self::COLOR_SCRIM;
        $textEnd = self::MARGIN + self::COLUMN_WIDTH;

        for ($x = 0; $x < self::WIDTH; $x++) {
            $offset = $rightAligned ? self::WIDTH - 1 - $x : $x;
            $alpha = (int) round(
                $offset <= $textEnd
                    ? 6 + 24 * $offset / $textEnd
                    : 30 + 72 * ($offset - $textEnd) / (self::WIDTH - $textEnd),
            );
            $color = imagecolorallocatealpha($canvas, $red, $green, $blue, $alpha);

            if ($color !== false) {
                imageline($canvas, $x, 0, $x, $height - 1, $color);
            }
        }
    }

    private function paintBrandMotif(GdImage $canvas, bool $rightAligned, int $height): void
    {
        $centerX = $rightAligned ? 112 : self::WIDTH - 112;
        $centerY = intdiv($height, 2);
        $colors = [[35, 77, 63], [46, 91, 73], [61, 108, 85]];

        foreach ([310, 245, 180] as $index => $radius) {
            $color = $this->allocate($canvas, $colors[$index]);

            if ($color !== null) {
                imagefilledpolygon($canvas, [
                    $centerX, $centerY - $radius,
                    $centerX + $radius, $centerY,
                    $centerX, $centerY + $radius,
                    $centerX - $radius, $centerY,
                ], $color);
            }
        }
    }

    private function paintCoverFit(GdImage $canvas, GdImage $raster, int $dstX, int $dstY, int $dstW, int $dstH, int $panel): void
    {
        $srcW = imagesx($raster);
        $srcH = imagesy($raster);

        $scale = max($dstW / $srcW, $dstH / $srcH);
        $tmpW = (int) ceil($srcW * $scale);
        $tmpH = (int) ceil($srcH * $scale);

        if ($tmpW <= 0 || $tmpH <= 0 || $tmpW * $tmpH > self::MAX_UPSCALE_PIXELS) {
            return;
        }

        $scaled = imagecreatetruecolor($tmpW, $tmpH);

        if (! $scaled instanceof GdImage) {
            return;
        }

        imagefill($scaled, 0, 0, $panel);
        imagecopyresampled($scaled, $raster, 0, 0, 0, 0, $tmpW, $tmpH, $srcW, $srcH);

        $srcX = (int) floor(($tmpW - $dstW) / 2);
        $srcY = (int) floor(($tmpH - $dstH) / 2);
        imagecopy($canvas, $scaled, $dstX, $dstY, $srcX, $srcY, $dstW, $dstH);
    }

    /**
     * @param  list<string>  $createdFiles
     */
    private function cleanupRunDirectory(string $runDirectory, array $createdFiles): void
    {
        foreach ($createdFiles as $file) {
            if (str_starts_with($file, $runDirectory.'/')) {
                @unlink($file);
            }
        }

        @rmdir($runDirectory);
    }
}
