<?php

declare(strict_types=1);

namespace App\Platform;

use RuntimeException;

/** Source-controlled ownership metadata; no database, container or parser dependency. */
final class FeatureCatalog
{
    /** @return array{features: array<string, string>, owners: array<string, string>, writes: array<string, list<string>>, aliases: array<string, array<string, string>>} */
    public static function load(string $root): array
    {
        $features = $owners = $writes = $aliases = [];
        $paths = glob($root.'/app/Features/*', GLOB_ONLYDIR) ?: [];
        sort($paths, SORT_STRING);

        foreach ($paths as $folder) {
            $name = basename($folder);
            $path = $folder.'/feature.json';
            if (! is_file($path)) {
                throw new RuntimeException("Missing {$path}");
            }
            $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (! preg_match('/^[A-Z][A-Za-z0-9]*$/D', $name) || ($data['schema'] ?? null) !== 2
                || ($data['name'] ?? null) !== $name || ! is_array($data['owns'] ?? null)
                || ! is_file($folder.'/FeatureServiceProvider.php')) {
                throw new RuntimeException("Invalid feature/provider manifest: {$path}");
            }
            $features[$name] = $folder;
            foreach ($data['owns'] as $resource => $actions) {
                if (! is_string($resource) || ! preg_match('/^(table|store):[A-Za-z0-9_.:-]+$/D', $resource)
                    || ! is_array($actions) || ! array_is_list($actions) || $actions === []
                    || count($actions) !== count(array_unique($actions, SORT_REGULAR))) {
                    throw new RuntimeException("{$path}: each resource requires a nonempty list of named local write classes.");
                }
                if (isset($owners[$resource])) {
                    throw new RuntimeException("Duplicate owner: {$resource} belongs to {$owners[$resource]}, also claimed by {$name}");
                }
                $owners[$resource] = $name;
                foreach ($actions as $class) {
                    $prefix = "App\\Features\\{$name}\\";
                    if (! is_string($class) || ! str_starts_with($class, $prefix)
                        || ! preg_match('/^(Actions|Persistence|Contributors)\\\\[A-Za-z_][A-Za-z0-9_\\\\]*$/D', substr($class, strlen($prefix)))) {
                        throw new RuntimeException("{$resource}: write classes must be under {$prefix}Actions, Persistence or Contributors.");
                    }
                    $file = $root.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
                    if (! is_file($file) || ! str_starts_with(realpath($file), realpath($folder).DIRECTORY_SEPARATOR)) {
                        throw new RuntimeException("Missing/escaped write class: {$class}");
                    }
                    $writes[$class][] = $resource;
                }
            }

            $declaredAliases = $data['aliases'] ?? [];
            if (! is_array($declaredAliases)) {
                throw new RuntimeException("{$path}: aliases must be an object mapping alias to owned resource.");
            }
            foreach ($declaredAliases as $alias => $aliasResource) {
                if (! is_string($alias) || ! preg_match('/^[a-z][a-z0-9_]*$/D', $alias)
                    || ! is_string($aliasResource) || ! isset($data['owns'][$aliasResource])) {
                    throw new RuntimeException("{$path}: alias [{$alias}] must map to a resource owned by this feature.");
                }
                $aliases[$name][$alias] = $aliasResource;
            }
        }

        return ['features' => $features, 'owners' => $owners, 'writes' => $writes, 'aliases' => $aliases];
    }
}
