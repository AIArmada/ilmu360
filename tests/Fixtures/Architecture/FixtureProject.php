<?php

declare(strict_types=1);

namespace Tests\Fixtures\Architecture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final readonly class FixtureProject
{
    public string $path;

    public function __construct()
    {
        $this->path = sys_get_temp_dir().'/ilmu360-architecture-'.bin2hex(random_bytes(12));
        mkdir($this->path, 0700);
        $autoload = var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true);

        $this->write('vendor/autoload.php', "<?php require {$autoload};");
        $this->configure();
    }

    /** @param list<array<string, string>> $exceptions */
    public function configure(array $exceptions = []): void
    {
        $this->write('tools/architecture.json', json_encode([
            'schema' => 2,
            'discovery' => 'native',
            'exceptions' => $exceptions,
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, list<string>> $owns */
    public function feature(string $name, array $owns = []): void
    {
        $this->write("app/Features/{$name}/feature.json", json_encode([
            'schema' => 2,
            'name' => $name,
            'owns' => $owns,
        ], JSON_THROW_ON_ERROR));
        $this->write("app/Features/{$name}/FeatureServiceProvider.php", "<?php\nnamespace App\\Features\\{$name};\nclass FeatureServiceProvider {}\n");
    }

    public function writer(string $feature, string $name, string $directory = 'Actions'): string
    {
        $this->write("app/Features/{$feature}/{$directory}/{$name}.php", "<?php\nnamespace App\\Features\\{$feature}\\{$directory};\nclass {$name} {}\n");

        return "App\\Features\\{$feature}\\{$directory}\\{$name}";
    }

    public function write(string $relativePath, string $contents): void
    {
        $path = $this->path.'/'.$relativePath;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }

        file_put_contents($path, $contents);
    }

    /** @return array{exitCode: int, stdout: string, stderr: string} */
    public function check(): array
    {
        $process = proc_open([
            PHP_BINARY,
            dirname(__DIR__, 3).'/tools/architecture-check.php',
            '--root='.$this->path,
            '--json',
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the architecture checker.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exitCode' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    public function remove(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($this->path);
    }
}
