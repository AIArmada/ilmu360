<?php

declare(strict_types=1);

namespace App\Platform;

use RuntimeException;

/** Source-controlled ownership metadata; no database, container or parser dependency. */
final class FeatureCatalog
{
    /** @var list<string> */
    private const ROOT_STORE_WRITER_PREFIXES = [
        'App\\Console\\Commands\\',
        'App\\Filament\\Pages\\',
        'App\\Platform\\',
        'App\\Support\\',
    ];

    /**
     * @param  array<string, mixed>  $rootStoreWriters
     * @return array{features: array<string, string>, owners: array<string, string>, writes: array<string, list<string>>, aliases: array<string, array<string, string>>, rootStoreWriters: array<string, array{adapter: 'cache'|'filesystem'|'redis', resource: string, reason: string, expires: string}>}
     */
    public static function load(string $root, array $rootStoreWriters = []): array
    {
        $features = $owners = $writes = $aliases = $declaredAliases = [];
        $aliasResources = [];
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

            $featureAliases = $data['aliases'] ?? [];
            if (! is_array($featureAliases)) {
                throw new RuntimeException("{$path}: aliases must map model aliases to declared storage resources.");
            }
            foreach ($featureAliases as $alias => $aliasResource) {
                if (! is_string($alias) || ! preg_match('/^[a-z][a-z0-9_]*$/D', $alias)
                    || ! is_string($aliasResource) || ! preg_match('/^(table|store):[A-Za-z0-9_.:-]+$/D', $aliasResource)) {
                    throw new RuntimeException("{$path}: alias [{$alias}] must map to a declared storage resource.");
                }

                $declaredAliases[$name][$alias] = $aliasResource;
            }
        }

        foreach ($declaredAliases as $name => $featureAliases) {
            foreach ($featureAliases as $alias => $aliasResource) {
                if (! isset($owners[$aliasResource])) {
                    throw new RuntimeException("{$name}: alias [{$alias}] maps to an undeclared resource [{$aliasResource}].");
                }

                if (isset($aliasResources[$alias]) && $aliasResources[$alias] !== $aliasResource) {
                    throw new RuntimeException("Conflicting resource mappings for model alias [{$alias}].");
                }

                $aliases[$name][$alias] = $aliasResource;
                $aliasResources[$alias] = $aliasResource;
            }
        }

        $rootStoreWriters = self::validateRootStoreWriters($root, $rootStoreWriters, $owners, $writes);

        return [
            'features' => $features,
            'owners' => $owners,
            'writes' => $writes,
            'aliases' => $aliases,
            'rootStoreWriters' => $rootStoreWriters,
        ];
    }

    /**
     * @param  array<string, mixed>  $rootStoreWriters
     * @param  array<string, string>  $owners
     * @param  array<string, list<string>>  $writes
     * @return array<string, array{adapter: 'cache'|'filesystem'|'redis', resource: string, reason: string, expires: string}>
     */
    private static function validateRootStoreWriters(string $root, array $rootStoreWriters, array $owners, array $writes): array
    {
        if ($rootStoreWriters !== [] && array_is_list($rootStoreWriters)) {
            throw new RuntimeException('Root store writers must map exact classes to named unowned stores.');
        }

        $resolvedRoot = realpath($root);

        foreach ($rootStoreWriters as $class => $writer) {
            if (! is_string($class)
                || ! preg_match('/^App\\\\[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/D', $class)) {
                throw new RuntimeException('Root store writer keys must be exact fully-qualified class paths.');
            }

            if (! is_array($writer) || array_is_list($writer)
                || array_diff(array_keys($writer), ['adapter', 'resource', 'reason', 'expires']) !== []
                || array_diff(['adapter', 'resource', 'reason', 'expires'], array_keys($writer)) !== []) {
                throw new RuntimeException("Root store writer entry must declare adapter, resource, reason and expires: {$class}");
            }

            $adapter = $writer['adapter'];
            $resource = $writer['resource'];
            $reason = $writer['reason'];
            $expires = $writer['expires'];

            if (! is_string($adapter) || ! in_array($adapter, ['cache', 'filesystem', 'redis'], true)) {
                throw new RuntimeException("Root store writer requires an adapter family (cache, filesystem or redis): {$class}");
            }

            if (isset($writes[$class])) {
                throw new RuntimeException("Root store writer collides with feature writer: {$class}");
            }

            $isAllowedNamespace = false;

            foreach (self::ROOT_STORE_WRITER_PREFIXES as $prefix) {
                if (str_starts_with($class, $prefix)) {
                    $isAllowedNamespace = true;

                    break;
                }
            }

            if (! $isAllowedNamespace) {
                throw new RuntimeException("Root store writer must use an allowlisted non-feature namespace: {$class}");
            }

            if (! is_string($resource) || ! preg_match('/^store:[A-Za-z0-9_.:-]+$/D', $resource)
                || $resource === 'store:unknown') {
                throw new RuntimeException("Root store writer requires a named store resource: {$class}");
            }

            if (isset($owners[$resource])) {
                throw new RuntimeException("Root store writer cannot target feature-owned resource: {$resource}");
            }

            if (! is_string($reason) || trim($reason) === '') {
                throw new RuntimeException("Root store writer requires a nonempty reason: {$class}");
            }

            if (! is_string($expires)) {
                throw new RuntimeException("Root store writer requires a future expiry date: {$class}");
            }

            $expiryDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $expires);

            if (! $expiryDate || $expiryDate->format('Y-m-d') !== $expires || $expires <= gmdate('Y-m-d')) {
                throw new RuntimeException("Invalid/expired root store writer: {$class} {$resource}");
            }

            $file = $root.'/app/'.str_replace('\\', '/', substr($class, strlen('App\\'))).'.php';
            $resolvedFile = realpath($file);

            if ($resolvedRoot === false || $resolvedFile === false || ! is_file($resolvedFile)
                || ! str_starts_with($resolvedFile, $resolvedRoot.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException("Missing/escaped root store writer: {$class}");
            }

            $rootStoreWriters[$class] = [
                'adapter' => $adapter,
                'resource' => $resource,
                'reason' => $reason,
                'expires' => $expires,
            ];
        }

        return $rootStoreWriters;
    }
}
