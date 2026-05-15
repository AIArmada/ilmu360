<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation;

use Symfony\Component\Finder\Finder;

class ApiDocumentationVersionResolver
{
    public function current(): string
    {
        $context = hash_init('sha1');

        hash_update($context, $this->configFingerprint());
        hash_update($context, "\n");

        foreach ($this->trackedFiles() as $filePath) {
            hash_update($context, $this->fileFingerprint($filePath));
            hash_update($context, "\n");
        }

        return hash_final($context);
    }

    /**
     * @return list<string>
     */
    private function trackedFiles(): array
    {
        $files = [];

        foreach ($this->trackedPaths() as $path) {
            if (is_file($path)) {
                $files[] = realpath($path) ?: $path;

                continue;
            }

            if (! is_dir($path)) {
                continue;
            }

            foreach (Finder::create()->files()->in($path)->name('*.php')->sortByName() as $file) {
                $files[] = $file->getRealPath() ?: $file->getPathname();
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function trackedPaths(): array
    {
        return [
            app_path(),
            base_path('routes'),
            config_path('scramble.php'),
            base_path('docs/ilmu360_mobile_api_reference.md'),
            base_path('composer.lock'),
        ];
    }

    private function relativePath(string $filePath): string
    {
        return ltrim(str_replace(base_path(), '', $filePath), DIRECTORY_SEPARATOR);
    }

    private function fileFingerprint(string $filePath): string
    {
        $modifiedAt = filemtime($filePath);
        $fileSize = filesize($filePath);

        return implode('|', [
            $this->relativePath($filePath),
            is_int($modifiedAt) ? (string) $modifiedAt : '0',
            is_int($fileSize) ? (string) $fileSize : '0',
        ]);
    }

    private function configFingerprint(): string
    {
        $payload = [
            'scramble' => config('scramble'),
        ];

        $encodedPayload = json_encode($payload);

        if ($encodedPayload !== false) {
            return $encodedPayload;
        }

        return serialize($payload);
    }
}
