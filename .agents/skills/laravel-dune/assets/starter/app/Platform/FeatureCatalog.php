<?php

declare(strict_types=1);

namespace App\Platform;

use RuntimeException;

final class FeatureCatalog
{
    /** @var array<string, array{features: array<string, string>, owners: array<string, string>, writes: array<string, list<string>>, extensions: array<string, list<string>>}> */
    private static array $memory = [];

    public static function flushMemory(): void
    {
        self::$memory = [];
    }

    public static function cachePath(string $root): string
    {
        return rtrim($root, '/').'/bootstrap/cache/platform.php';
    }

    /**
     * @return array{
     *     features: array<string, string>,
     *     owners: array<string, string>,
     *     writes: array<string, list<string>>,
     *     extensions: array<string, list<string>>
     * }
     */
    public static function load(string $root, bool $useCache = true): array
    {
        $normalized = rtrim($root, '/');

        if ($useCache && isset(self::$memory[$normalized])) {
            return self::$memory[$normalized];
        }

        $cacheFile = self::cachePath($normalized);
        if ($useCache && is_file($cacheFile)) {
            /** @var mixed $cached */
            $cached = require $cacheFile;
            if (is_array($cached) && isset($cached['features'], $cached['owners'], $cached['writes'], $cached['extensions'])) {
                return self::$memory[$normalized] = $cached;
            }
        }

        $catalog = self::scan($normalized);

        if ($useCache) {
            self::$memory[$normalized] = $catalog;
        }

        return $catalog;
    }

    /**
     * @return array{
     *     features: array<string, string>,
     *     owners: array<string, string>,
     *     writes: array<string, list<string>>,
     *     extensions: array<string, list<string>>
     * }
     */
    public static function scan(string $root): array
    {
        $features = [];
        $owners = [];
        $writes = [];
        $extensions = [];

        $paths = glob($root.'/app/Features/*', GLOB_ONLYDIR) ?: [];
        sort($paths, SORT_STRING);

        foreach ($paths as $folder) {
            $name = basename($folder);
            $manifestPath = $folder.'/feature.json';

            if (! is_file($manifestPath)) {
                throw new RuntimeException("Missing feature manifest: {$manifestPath}. Every folder in app/Features/ must declare feature.json.");
            }

            $raw = file_get_contents($manifestPath);
            if ($raw === false) {
                throw new RuntimeException("Unable to read {$manifestPath}.");
            }

            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            if (! preg_match('/^[A-Z][A-Za-z0-9]*$/D', $name)
                || ($data['schema'] ?? null) !== 1
                || ($data['name'] ?? null) !== $name
                || ! is_array($data['owns'] ?? null)
                || ! is_file($folder.'/FeatureServiceProvider.php')) {
                throw new RuntimeException("Invalid feature manifest or missing FeatureServiceProvider in {$manifestPath}.");
            }

            $features[$name] = $folder;
            $realFolder = realpath($folder);
            $prefix = "App\\Features\\{$name}\\";

            foreach ($data['owns'] as $resource => $classes) {
                if (! is_string($resource)
                    || ! preg_match('/^(table|store):[A-Za-z0-9_.:-]+$/D', $resource)
                    || ! is_array($classes)
                    || ! array_is_list($classes)
                    || $classes === []
                    || count($classes) !== count(array_unique($classes, SORT_REGULAR))) {
                    throw new RuntimeException("{$manifestPath}: resource '{$resource}' requires a non-empty unique list of owning Actions/Persistence FQCNs.");
                }

                if (isset($owners[$resource])) {
                    throw new RuntimeException("Duplicate durable owner: '{$resource}' belongs to {$owners[$resource]}, also claimed by {$name}.");
                }

                $owners[$resource] = $name;

                foreach ($classes as $class) {
                    if (! is_string($class)
                        || ! str_starts_with($class, $prefix)
                        || ! preg_match('/^(Actions|Persistence)\\\\[A-Za-z_][A-Za-z0-9_\\\\]*$/D', substr($class, strlen($prefix)))) {
                        throw new RuntimeException("{$manifestPath}: write class '{$class}' for '{$resource}' must live under {$prefix}Actions\\ or {$prefix}Persistence\\.");
                    }

                    $file = $root.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
                    $realFile = realpath($file);
                    if ($realFile === false || $realFolder === false || ! str_starts_with($realFile, $realFolder.DIRECTORY_SEPARATOR)) {
                        throw new RuntimeException("{$manifestPath}: declared write class '{$class}' does not exist at {$file}.");
                    }

                    $writes[$class][] = $resource;
                }
            }

            if (isset($data['extensions'])) {
                if (! is_array($data['extensions'])) {
                    throw new RuntimeException("{$manifestPath}: 'extensions' must be a map of slot => list<FQCN>.");
                }

                foreach ($data['extensions'] as $slot => $extClasses) {
                    if (! is_string($slot) || ! preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $slot) || ! is_array($extClasses) || ! array_is_list($extClasses)) {
                        throw new RuntimeException("{$manifestPath}: invalid extension slot '{$slot}'.");
                    }

                    foreach ($extClasses as $extClass) {
                        if (! is_string($extClass)
                            || ! str_starts_with($extClass, $prefix)
                            || ! preg_match('/^Extensions\\\\[A-Za-z_][A-Za-z0-9_\\\\]*$/D', substr($extClass, strlen($prefix)))) {
                            throw new RuntimeException("{$manifestPath}: extension '{$extClass}' for slot '{$slot}' must live under {$prefix}Extensions\\.");
                        }

                        $extFile = $root.'/app/'.str_replace('\\', '/', substr($extClass, 4)).'.php';
                        $realExt = realpath($extFile);
                        if ($realExt === false || $realFolder === false || ! str_starts_with($realExt, $realFolder.DIRECTORY_SEPARATOR)) {
                            throw new RuntimeException("{$manifestPath}: extension class '{$extClass}' not found at {$extFile}.");
                        }

                        $extensions[$slot][] = $extClass;
                    }
                }
            }
        }

        ksort($extensions, SORT_STRING);

        return compact('features', 'owners', 'writes', 'extensions');
    }
}
