<?php

declare(strict_types=1);

// AST dependency and ownership guard; writes are checked by the separate PHPStan rule. Does not boot Laravel or connect to a database.
use App\Platform\FeatureCatalog;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

$options = getopt('', ['root:', 'json']);
$root = realpath($options['root'] ?? dirname(__DIR__));
if (! $root || ! is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR, "Run composer install (with dev dependencies) first.\n");
    exit(2);
}
require $root.'/vendor/autoload.php';
if (! class_exists(ParserFactory::class)) {
    fwrite(STDERR, "Install the checker dependency: composer require --dev 'nikic/php-parser:^5'\n");
    exit(2);
}

$findings = [];
$report = static function (string $rule, string $file, int $line, string $target, string $message) use (&$findings): void {
    $key = $rule.'|'.$file.'|'.$line.'|'.$target;
    $findings[$key] = ['rule' => $rule, 'file' => $file, 'line' => $line, 'target' => $target, 'message' => $message];
};
$featureOf = (static fn (string $name): ?string => preg_match('/^App\\\\Features\\\\([A-Z][A-Za-z0-9]*)\\\\/', $name, $match) ? $match[1] : null);
$finder = new NodeFinder;
$parser = (new ParserFactory)->createForNewestSupportedVersion();

try {
    $config = json_decode(file_get_contents($root.'/tools/architecture.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($config['schema'] ?? null) !== 2 || ! is_array($config['exceptions'] ?? null)
        || ! in_array($config['discovery'] ?? null, ['native', 'auto'], true)) {
        throw new RuntimeException('Invalid tools/architecture.json schema.');
    }
    $catalog = FeatureCatalog::load($root);
    $owners = $catalog['owners'];
    $features = $catalog['features'];

    $paths = [];
    foreach (['app', 'routes', 'bootstrap', 'config'] as $directory) {
        if (! is_dir($root.'/'.$directory)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($file->isFile() && $file->getExtension() === 'php' && ! str_starts_with($relative, 'bootstrap/cache/')) {
                $paths[$relative] = $file->getPathname();
            }
        }
    }
    ksort($paths);
    foreach ($paths as $relative => $path) {
        if (! preg_match('~^app/Features/[^/]+/Models/~', $relative)) {
            continue;
        }
        $modelAst = $parser->parse(file_get_contents($path)) ?? [];
        $resolver = new NodeTraverser;
        $resolver->addVisitor(new NameResolver);
        $modelAst = $resolver->traverse($modelAst);
        foreach ($finder->findInstanceOf($modelAst, Node\Stmt\Class_::class) as $class) {
            if ($class->name === null || $class->isAbstract()) {
                continue;
            }
            $table = null;
            foreach ($class->getProperties() as $property) {
                foreach ($property->props as $prop) {
                    if ($prop->name->toString() === 'table' && $prop->default instanceof Node\Scalar\String_) {
                        $table = $prop->default->value;
                    }
                }
            }
            $declared = isset($class->namespacedName) ? $class->namespacedName->toString() : $class->name->toString();
            if ($table === null) {
                $report('model-table', $relative, $class->getStartLine(), $declared, 'Declare an explicit literal $table on feature models so ownership survives moves and can be checked.');
            }
        }
    }
    foreach ($paths as $relative => $path) {
        $ast = $parser->parse(file_get_contents($path)) ?? [];
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $ast = $traverser->traverse($ast);
        $sourceFeature = preg_match('~^app/Features/([^/]+)/~', $relative, $match) ? $match[1] : null;
        $platform = str_starts_with($relative, 'app/Platform/');
        $shared = str_starts_with($relative, 'app/Shared/');
        $public = $sourceFeature && str_contains($relative, '/Api/');
        $models = $sourceFeature && str_contains($relative, '/Models/');
        $expectedClass = 'App\\'.str_replace('/', '\\', substr($relative, 4, -4));

        foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $class) {
            if ($sourceFeature && $class->name !== null) {
                $declared = isset($class->namespacedName) ? $class->namespacedName->toString() : $class->name->toString();
                if ($declared !== $expectedClass) {
                    $report('namespace-path', $relative, $class->getStartLine(), $declared, "Class must match its PSR-4 path: {$expectedClass}");
                }
            }
            if ($public && $class instanceof Node\Stmt\Class_ && (! $class->isReadonly() || ! $class->isFinal())) {
                $report('api-shape', $relative, $class->getStartLine(), $expectedClass, 'Public Api classes are final readonly values; add behavior as a boundary interface only when needed.');
            }
        }

        $checkDependency = static function (string $target, int $line) use ($sourceFeature, $platform, $shared, $public, $relative, $featureOf, $report, $features): void {
            $target = ltrim($target, '\\');
            $targetFeature = $featureOf($target);
            if ($targetFeature && ! isset($features[$targetFeature])) {
                $report('missing-feature', $relative, $line, $target, 'Referenced feature has no discovered manifest.');
            }
            if (($platform || $shared) && $targetFeature) {
                $report('platform-direction', $relative, $line, $target, 'Platform/Shared must not depend on concrete features; use a generic contract.');
            } elseif ($targetFeature && ($target !== "App\\Features\\{$targetFeature}\\FeatureServiceProvider" || ! in_array($relative, ['bootstrap/providers.php', 'config/app.php'], true)) && $targetFeature !== $sourceFeature && ! str_starts_with($target, "App\\Features\\{$targetFeature}\\Api\\")) {
                $report('feature-private', $relative, $line, $target, "Call {$targetFeature} through App\\Features\\{$targetFeature}\\Api; private types cannot cross its boundary.");
            }
            if ($public && str_starts_with($target, 'App\\') && ! str_contains($target, '\\Api\\') && ! str_starts_with($target, 'App\\Shared\\')) {
                $report('api-leak', $relative, $line, $target, 'Public signatures and values may depend only on public Api or Shared contracts/values.');
            }
            if ($public && str_starts_with($target, 'Illuminate\\')) {
                $report('api-shape', $relative, $line, $target, 'Api contracts/values must not expose Laravel runtime types.');
            }
            if ($sourceFeature && str_starts_with($target, 'App\\') && ! $targetFeature
                && ! str_starts_with($target, 'App\\Platform\\') && ! str_starts_with($target, 'App\\Shared\\')) {
                $report('feature-external', $relative, $line, $target, 'Move this dependency into its owning feature or expose an explicit Platform/Shared contract.');
            }
        };

        foreach ($finder->find($ast, static fn (Node $node): bool => true) as $node) {
            if ($node instanceof Node\Stmt\Use_) {
                foreach ($node->uses as $use) {
                    $checkDependency($use->name->toString(), $use->getStartLine());
                }
            }
            if ($node instanceof Node\Stmt\GroupUse) {
                foreach ($node->uses as $use) {
                    $checkDependency($node->prefix->toString().'\\'.$use->name->toString(), $use->getStartLine());
                }
            }
            if ($node instanceof Node\Name\FullyQualified) {
                $checkDependency($node->toString(), $node->getStartLine());
            }
            if ($node instanceof Node\Scalar\String_ && str_starts_with(ltrim($node->value, '\\'), 'App\\')) {
                $checkDependency($node->value, $node->getStartLine());
            }
            if ($models && $node instanceof Node\Stmt\PropertyProperty && $node->name->toString() === 'table'
                && $node->default instanceof Node\Scalar\String_ && ($owners['table:'.$node->default->value] ?? null) !== $sourceFeature) {
                $report('resource-owner', $relative, $node->getStartLine(), 'table:'.$node->default->value, 'Model table must be declared in this feature manifest.');
            }
        }
    }

    $suppressible = ['feature-private'];
    foreach ($config['exceptions'] as $exception) {
        foreach (['rule', 'file', 'target', 'reason', 'owner', 'expires'] as $field) {
            if (! is_string($exception[$field] ?? null) || trim($exception[$field]) === '') {
                throw new RuntimeException("Exception requires nonempty {$field}");
            }
        }
        if (! in_array($exception['rule'], $suppressible, true) || strpbrk($exception['file'].$exception['target'], '*?') !== false) {
            throw new RuntimeException('Exceptions require an allowed rule and exact file/target (no wildcard).');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $exception['expires']);
        if (! $date || $date->format('Y-m-d') !== $exception['expires'] || $exception['expires'] < gmdate('Y-m-d')) {
            throw new RuntimeException('Invalid/expired exception: '.$exception['file'].' '.$exception['target']);
        }
        $used = false;
        foreach ($findings as $key => $finding) {
            if ($finding['rule'] === $exception['rule'] && $finding['file'] === $exception['file'] && $finding['target'] === $exception['target']) {
                unset($findings[$key]);
                $used = true;
            }
        }
        if (! $used) {
            throw new RuntimeException('Remove stale exception: '.$exception['file'].' '.$exception['target']);
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Architecture configuration/parser error: '.$error->getMessage()."\n");
    exit(2);
}

$findings = array_values($findings);
if (isset($options['json'])) {
    echo json_encode(['schema' => 2, 'findings' => $findings, 'features' => array_keys($features)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
} else {
    foreach ($findings as $finding) {
        echo "{$finding['file']}:{$finding['line']} [{$finding['rule']}] {$finding['target']}: {$finding['message']}\n";
    }
    echo count($findings).' architecture violation(s); '.count($features)." feature(s) checked.\n";
}
exit($findings !== [] ? 1 : 0);
