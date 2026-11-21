<?php

declare(strict_types=1);

use App\Platform\FeatureCatalog;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

$rawArgs = array_slice($_SERVER['argv'] ?? [], 1);
$expectValue = false;
$unknownOptions = [];
foreach ($rawArgs as $arg) {
    if ($expectValue) {
        $expectValue = false;
        continue;
    }
    if ($arg === '--json') {
        continue;
    }
    if ($arg === '--root' || $arg === '--diff-base') {
        $expectValue = true;
        continue;
    }
    if (str_starts_with($arg, '--root=') || str_starts_with($arg, '--diff-base=')) {
        continue;
    }
    $unknownOptions[] = $arg;
}
if ($expectValue) {
    fwrite(STDERR, 'Missing value for option: '.end($rawArgs)."\n");
    exit(2);
}
if ($unknownOptions !== []) {
    fwrite(STDERR, 'Unknown option(s): '.implode(' ', $unknownOptions)."\nAllowed: --root=<path>, --diff-base=<ref>, --json\n");
    exit(2);
}

$options = getopt('', ['root:', 'json', 'diff-base:']);
$root = realpath($options['root'] ?? dirname(__DIR__));
if ($root === false || ! is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR, "Run composer install (including dev dependencies) first.\n");
    exit(2);
}

require $root.'/vendor/autoload.php';

if (! class_exists(ParserFactory::class)) {
    fwrite(STDERR, "Install PHP-Parser: composer require --dev 'nikic/php-parser:^5'\n");
    exit(2);
}

$diffBase = is_string($options['diff-base'] ?? null) ? trim($options['diff-base']) : null;
$changedFiles = null;
if ($diffBase !== null && $diffBase !== '') {
    $cmd = sprintf('git -C %s diff --name-only --diff-filter=ACMRT %s 2>/dev/null', escapeshellarg($root), escapeshellarg($diffBase));
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Unable to compute git diff against '{$diffBase}'.\n");
        exit(2);
    }
    $changedFiles = array_flip(array_map(static fn (string $p): string => str_replace('\\', '/', trim($p)), $out));
}

$findings = [];
$report = static function (string $rule, string $file, int $line, string $target, string $message) use (&$findings): void {
    $key = $rule.'|'.$file.'|'.$line.'|'.$target;
    $findings[$key] = compact('rule', 'file', 'line', 'target', 'message');
};

$featureOf = static function (string $name): ?string {
    return preg_match('/^App\\\\Features\\\\([A-Z][A-Za-z0-9]*)\\\\/', $name, $match) ? $match[1] : null;
};

$finder = new NodeFinder();
$parser = (new ParserFactory())->createForNewestSupportedVersion();

try {
    $configPath = $root.'/tools/architecture.json';
    if (! is_file($configPath)) {
        throw new RuntimeException("Missing {$configPath}. Run arch_scaffold.py init --apply first.");
    }

    $config = json_decode((string) file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
    if (($config['schema'] ?? null) !== 1
        || ! is_array($config['exceptions'] ?? null)
        || ! in_array($config['discovery'] ?? null, ['auto', 'native'], true)) {
        throw new RuntimeException('Invalid tools/architecture.json schema.');
    }

    $antiSlop = is_array($config['antiSlop'] ?? null) ? $config['antiSlop'] : [];
    $catalog = FeatureCatalog::scan($root);
    $owners = $catalog['owners'];
    $features = $catalog['features'];

    $paths = [];
    foreach (['app', 'routes', 'bootstrap', 'config'] as $directory) {
        if (! is_dir($root.'/'.$directory)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if ($file->isFile() && $file->getExtension() === 'php' && ! str_starts_with($relative, 'bootstrap/cache/')) {
                $paths[$relative] = $file->getPathname();
            }
        }
    }
    ksort($paths);

    foreach ($paths as $relative => $path) {
        $sourceCode = (string) file_get_contents($path);
        $ast = $parser->parse($sourceCode) ?? [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $ast = $traverser->traverse($ast);

        $sourceFeature = preg_match('~^app/Features/([^/]+)/~', $relative, $match) ? $match[1] : null;
        $isPlatform = str_starts_with($relative, 'app/Platform/');
        $isHost = str_starts_with($relative, 'app/Host/');
        $isShared = str_starts_with($relative, 'app/Shared/');
        $isPublicApi = ($sourceFeature !== null && str_contains($relative, '/Api/'))
            || str_starts_with($relative, 'app/Shared/Contracts/');
        $isModelFile = $sourceFeature !== null && str_contains($relative, '/Models/');
        $isConfigFile = str_starts_with($relative, 'config/');
        $isArchOwned = $sourceFeature !== null || $isPlatform || $isHost || $isShared;
        $applyAntiSlop = $isArchOwned || ($changedFiles === null) || isset($changedFiles[$relative]);

        $expectedClass = str_starts_with($relative, 'app/')
            ? 'App\\'.str_replace('/', '\\', substr($relative, 4, -4))
            : null;

        foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $class) {
            if ($sourceFeature !== null && $class->name !== null && $expectedClass !== null) {
                $declared = isset($class->namespacedName) ? $class->namespacedName->toString() : $class->name->toString();
                if ($declared !== $expectedClass) {
                    $report(
                        'namespace-path',
                        $relative,
                        $class->getStartLine(),
                        $declared,
                        "PSR-4 path mismatch. Rename class or file so FQCN equals {$expectedClass}."
                    );
                }
            }

            if ($isPublicApi && $class instanceof Node\Stmt\Class_ && (! $class->isReadonly() || ! $class->isFinal())) {
                $report(
                    'api-shape',
                    $relative,
                    $class->getStartLine(),
                    $expectedClass ?? $class->name?->toString() ?? 'class',
                    'Public Api and Shared/Contracts classes must be `final readonly class` immutable DTOs (or use interface/enum).'
                );
            }

            if ($isModelFile && $class instanceof Node\Stmt\Class_ && ! $class->isAbstract()) {
                $table = null;
                foreach ($class->getProperties() as $property) {
                    foreach ($property->props as $prop) {
                        if ($prop->name->toString() === 'table' && $prop->default instanceof Node\Scalar\String_) {
                            $table = $prop->default->value;
                        }
                    }
                }
                $declared = isset($class->namespacedName) ? $class->namespacedName->toString() : ($class->name?->toString() ?? 'Model');
                if ($table === null) {
                    $report(
                        'model-table',
                        $relative,
                        $class->getStartLine(),
                        $declared,
                        "Declare an explicit literal `protected \$table = '...';` on feature models so ownership survives moves and OwnedWriteRule can verify writes."
                    );
                } elseif (($owners['table:'.$table] ?? null) !== $sourceFeature) {
                    $currentOwner = $owners['table:'.$table] ?? 'unclaimed';
                    $report(
                        'resource-owner',
                        $relative,
                        $class->getStartLine(),
                        'table:'.$table,
                        "Model table 'table:{$table}' is owned by '{$currentOwner}'. Register it under app/Features/{$sourceFeature}/feature.json or call {$currentOwner}'s Api."
                    );
                }
            }
        }

        $checkDependency = static function (string $target, int $line) use (
            $sourceFeature,
            $isPlatform,
            $isHost,
            $isShared,
            $isPublicApi,
            $relative,
            $featureOf,
            $report,
            $features
        ): void {
            $target = ltrim($target, '\\');
            $targetFeature = $featureOf($target);

            if ($targetFeature !== null && ! isset($features[$targetFeature])) {
                $report(
                    'missing-feature',
                    $relative,
                    $line,
                    $target,
                    "Referenced feature '{$targetFeature}' has no valid app/Features/{$targetFeature}/feature.json manifest."
                );
            }

            if ($isPlatform && ($targetFeature !== null || str_starts_with($target, 'App\\Host\\'))) {
                $report(
                    'layer-direction',
                    $relative,
                    $line,
                    $target,
                    'Platform core (app/Platform/) must NEVER import Features or Host code. Use manifests or generic contracts.'
                );
            } elseif (($isHost || $isShared) && $targetFeature !== null) {
                $report(
                    'layer-direction',
                    $relative,
                    $line,
                    $target,
                    'app/Host/ and app/Shared/ must NEVER depend on concrete Features. Move shared types to app/Shared/Contracts/.'
                );
            } elseif ($isShared && str_starts_with($target, 'App\\Host\\')) {
                $report(
                    'layer-direction',
                    $relative,
                    $line,
                    $target,
                    'app/Shared/ must NEVER import app/Host/ implementations. Keep contracts pure.'
                );
            } elseif ($targetFeature !== null
                && ! ($target === "App\\Features\\{$targetFeature}\\FeatureServiceProvider" && in_array($relative, ['bootstrap/providers.php', 'config/app.php'], true))
                && $targetFeature !== $sourceFeature
                && ! str_starts_with($target, "App\\Features\\{$targetFeature}\\Api\\")) {
                $report(
                    'feature-private',
                    $relative,
                    $line,
                    $target,
                    "Private class in '{$targetFeature}' cannot cross its feature boundary. Call {$targetFeature} via App\\Features\\{$targetFeature}\\Api\\."
                );
            }

            if ($isPublicApi && str_starts_with($target, 'App\\')
                && ! str_contains($target, '\\Api\\')
                && ! str_starts_with($target, 'App\\Shared\\Contracts\\')) {
                $report(
                    'api-leak',
                    $relative,
                    $line,
                    $target,
                    'Public Api and Shared/Contracts files may reference only Api or App\\Shared\\Contracts types.'
                );
            }

            if ($isPublicApi && str_starts_with($target, 'Illuminate\\')) {
                $report(
                    'api-shape',
                    $relative,
                    $line,
                    $target,
                    'Public Api and Shared/Contracts must not leak Illuminate\\* runtime types (use immutable DTOs, enums, or scalar IDs).'
                );
            }

            if ($sourceFeature !== null && str_starts_with($target, 'App\\Host\\')) {
                $report(
                    'host-direct',
                    $relative,
                    $line,
                    $target,
                    'Features must not import concrete App\\Host\\ implementations directly. Inject the interface from App\\Shared\\Contracts\\.'
                );
            }

            if ($sourceFeature !== null
                && str_starts_with($target, 'App\\')
                && $targetFeature === null
                && ! str_starts_with($target, 'App\\Platform\\')
                && ! str_starts_with($target, 'App\\Shared\\')) {
                $report(
                    'feature-external',
                    $relative,
                    $line,
                    $target,
                    'Move legacy dependency into its owning Feature, or isolate it behind App\\Shared\\Contracts\\ + App\\Host\\.'
                );
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

            if (! $applyAntiSlop) {
                continue;
            }

            if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
                $fn = strtolower($node->name->toString());
                if (($antiSlop['banSleep'] ?? true) && in_array($fn, ['sleep', 'usleep', 'time_nanosleep', 'time_sleep_until'], true)) {
                    $report(
                        'banned-footgun',
                        $relative,
                        $node->getStartLine(),
                        $fn.'()',
                        'Imperative sleep/timer hacks are banned in app code. Model delays with queued jobs (`->delay()`), backoff, or state transitions.'
                    );
                }
                if (($antiSlop['banEnvOutsideConfig'] ?? true) && ! $isConfigFile && $fn === 'env') {
                    $report(
                        'banned-footgun',
                        $relative,
                        $node->getStartLine(),
                        'env()',
                        'Calling env() outside config/ breaks when `php artisan config:cache` is active. Read from config() instead.'
                    );
                }
                if (in_array($fn, ['dd', 'dump', 'ray', 'var_dump'], true)) {
                    $report(
                        'banned-footgun',
                        $relative,
                        $node->getStartLine(),
                        $fn.'()',
                        'Debug dump calls are banned in application code. Use structured logging or verification tests.'
                    );
                }
            }

            if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
                $cls = ltrim($node->class->toString(), '\\');
                $method = strtolower($node->name->toString());
                if (($antiSlop['banModelUnguard'] ?? true) && $method === 'unguard' && str_ends_with($cls, 'Model')) {
                    $report(
                        'banned-footgun',
                        $relative,
                        $node->getStartLine(),
                        $cls.'::unguard',
                        'Model::unguard() is banned. Declare explicit $fillable or construct validated attributes in the owning Action.'
                    );
                }
                if (($antiSlop['banSleep'] ?? true) && $cls === 'Illuminate\\Support\\Sleep' && in_array($method, ['for', 'sleep', 'usleep'], true)) {
                    $report(
                        'banned-footgun',
                        $relative,
                        $node->getStartLine(),
                        'Sleep::'.$method,
                        'Imperative Sleep hacks are banned in app code. Use queued jobs or explicit state transitions.'
                    );
                }
            }
        }

        if ($applyAntiSlop && ! $isPlatform) {
            $tokens = token_get_all($sourceCode);
            foreach ($tokens as $token) {
                if (! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $text = $token[1];
                $line = $token[2];

                if (($antiSlop['banInlineSuppressions'] ?? true)
                    && preg_match('/@phpstan-ignore|phpcs:(?:disable|ignore)|@psalm-suppress/i', $text)) {
                    $report(
                        'no-suppression',
                        $relative,
                        $line,
                        trim(strtok($text, "\n") ?: 'suppression'),
                        'Inline type/lint suppressions are banned. Fix the root cause or record an exact expiry-dated exception in tools/architecture.json.'
                    );
                }

                if (($antiSlop['commentHygiene'] ?? true)
                    && $token[0] === T_COMMENT
                    && ! preg_match('/license|copyright|spdx|https?:\/\/|prettier-ignore/i', $text)) {
                    if (preg_match('/\b(do not remove|don\'t remove|do not touch|temporary workaround|quick fix|fine for now|too risky|hack|todo|fixme|said never)\b/i', $text)) {
                        $report(
                            'comment-hygiene',
                            $relative,
                            $line,
                            trim(strtok($text, "\n") ?: 'comment'),
                            'Workaround or claimed-constraint comments are unenforceable liabilities. Encode the invariant as a type, lint, or test and delete the comment.'
                        );
                    }
                }
            }
        }
    }

    $suppressible = ['feature-private', 'feature-external'];
    foreach ($config['exceptions'] as $exception) {
        foreach (['rule', 'file', 'target', 'reason', 'owner', 'expires'] as $field) {
            if (! is_string($exception[$field] ?? null) || trim($exception[$field]) === '') {
                throw new RuntimeException("Architecture exception requires non-empty '{$field}'.");
            }
        }
        if (! in_array($exception['rule'], $suppressible, true) || strpbrk($exception['file'].$exception['target'], '*?') !== false) {
            throw new RuntimeException("Exception for {$exception['file']} must use an allowed rule and exact file/target (no wildcards).");
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $exception['expires']);
        if ($date === false || $date->format('Y-m-d') !== $exception['expires'] || $exception['expires'] < gmdate('Y-m-d')) {
            throw new RuntimeException("Expired or invalid exception date ({$exception['expires']}): {$exception['file']} -> {$exception['target']}");
        }

        $matched = false;
        foreach ($findings as $key => $finding) {
            if ($finding['rule'] === $exception['rule']
                && $finding['file'] === $exception['file']
                && $finding['target'] === $exception['target']) {
                unset($findings[$key]);
                $matched = true;
            }
        }
        if (! $matched) {
            throw new RuntimeException("Stale unused exception in tools/architecture.json — remove it: {$exception['file']} -> {$exception['target']}");
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Architecture check error: '.$error->getMessage()."\n");
    exit(2);
}

$findings = array_values($findings);
if (isset($options['json'])) {
    echo json_encode([
        'schema' => 1,
        'features' => array_keys($features),
        'findings' => $findings,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
} else {
    foreach ($findings as $finding) {
        echo "{$finding['file']}:{$finding['line']} [{$finding['rule']}] {$finding['target']}: {$finding['message']}\n";
    }
    echo count($findings).' architecture violation(s); '.count($features)." feature(s) checked.\n";
}

exit($findings !== [] ? 1 : 0);
