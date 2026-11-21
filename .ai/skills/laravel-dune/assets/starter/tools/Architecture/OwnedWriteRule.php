<?php

declare(strict_types=1);

namespace AppArchitecture\StaticAnalysis;

use App\Platform\FeatureCatalog;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/** @implements Rule<Node\Expr> */
final class OwnedWriteRule implements Rule
{
    /** @var array{features: array<string, string>, owners: array<string, string>, writes: array<string, list<string>>, extensions: array<string, list<string>>} */
    private array $catalog;

    /** @var array<string, ?string> */
    private array $modelResources = [];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly string $projectRoot,
    ) {
        $this->catalog = FeatureCatalog::scan($projectRoot);
    }

    public function getNodeType(): string
    {
        return Node\Expr::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node instanceof Node\Expr\MethodCall
            && ! $node instanceof Node\Expr\NullsafeMethodCall
            && ! $node instanceof Node\Expr\StaticCall) {
            return [];
        }

        if (! $node->name instanceof Node\Identifier) {
            return [];
        }

        $method = strtolower($node->name->toString());

        $type = $node instanceof Node\Expr\StaticCall
            ? ($node->class instanceof Node\Name ? new ObjectType($scope->resolveName($node->class)) : $scope->getType($node->class))
            : $scope->getType($node->var);

        $dbMethods = [
            'save', 'savequietly', 'saveorfail', 'push', 'pushquietly',
            'create', 'createquietly', 'forcecreate', 'forcecreatequietly',
            'update', 'updatequietly', 'updateorcreate', 'firstorcreate', 'createorfirst', 'incrementorcreate',
            'delete', 'deletequietly', 'destroy', 'forcedestroy', 'forcedelete',
            'restore', 'restoreorcreate', 'createorrestore',
            'increment', 'decrement', 'incrementquietly', 'decrementquietly',
            'touch', 'touchquietly', 'touchowners', 'incrementeach', 'decrementeach',
            'insert', 'insertgetid', 'insertorignore', 'insertusing', 'upsert', 'truncate',
            'attach', 'detach', 'sync', 'syncwithoutdetaching', 'syncwithpivotvalues', 'toggle', 'updateexistingpivot',
            'savemany', 'savemanyquietly', 'createmany', 'createmanyquietly', 'updatefrom',
            'statement', 'unprepared', 'affectingstatement',
        ];

        $storeMethods = [
            'put', 'putfile', 'putfileas', 'set', 'setex', 'setnx', 'mset', 'hset', 'hmset', 'hdel', 'del',
            'rpush', 'lpush', 'lpop', 'rpop', 'sadd', 'srem', 'zadd', 'zrem',
            'write', 'writestream', 'delete', 'deletedirectory', 'makedirectory',
            'copy', 'move', 'append', 'prepend', 'forget', 'flush', 'forever',
            'remember', 'rememberforever', 'add', 'increment', 'decrement', 'pull',
        ];

        $isDatabase = $this->isA($type, [
            'Illuminate\Database\Eloquent\Model',
            'Illuminate\Database\Eloquent\Builder',
            'Illuminate\Database\Eloquent\Relations\Relation',
            'Illuminate\Database\Query\Builder',
            'Illuminate\Database\ConnectionInterface',
            'Illuminate\Support\Facades\DB',
        ]);

        $isStore = $this->isA($type, [
            'Illuminate\Contracts\Cache\Repository',
            'Illuminate\Contracts\Cache\Store',
            'Illuminate\Contracts\Filesystem\Filesystem',
            'Illuminate\Redis\Connections\Connection',
            'Illuminate\Support\Facades\Cache',
            'Illuminate\Support\Facades\Storage',
            'Illuminate\Support\Facades\Redis',
        ]);

        if (! ($isDatabase && in_array($method, $dbMethods, true)) && ! ($isStore && in_array($method, $storeMethods, true))) {
            return [];
        }

        $file = str_replace('\\', '/', $scope->getFile());
        $relative = ltrim(substr($file, strlen(str_replace('\\', '/', $this->projectRoot))), '/');

        if (str_contains($relative, '/Database/Migrations/')) {
            return [];
        }

        $feature = preg_match('~^app/Features/([^/]+)/~', $relative, $match) ? $match[1] : null;
        $class = $scope->getClassReflection()?->getName();
        $declaredResources = $class === null ? [] : ($this->catalog['writes'][$class] ?? []);

        $resource = $isDatabase ? $this->tableResource($node) : $this->storeResource($node);

        if ($isDatabase) {
            $modelClasses = $type->getObjectClassNames();
            $templateModels = [
                $type->getTemplateType('Illuminate\Database\Eloquent\Builder', 'TModel'),
                $type->getTemplateType('Illuminate\Database\Eloquent\Relations\Relation', 'TRelatedModel'),
            ];
            foreach ($templateModels as $templateType) {
                foreach ($templateType->getObjectClassNames() as $model) {
                    $modelClasses[] = $model;
                }
            }
            foreach ($modelClasses as $model) {
                if ($this->isA(new ObjectType($model), ['Illuminate\Database\Eloquent\Model'])) {
                    $resource ??= $this->modelResource($model);
                }
            }
        }

        $owner = $resource === null ? null : ($this->catalog['owners'][$resource] ?? null);

        if ($feature === null && $owner === null) {
            return [];
        }

        if ($owner !== null && $owner !== $feature) {
            return [$this->error(
                "Durable resource '{$resource}' belongs to feature '{$owner}'. Call App\\Features\\{$owner}\\Api\\ instead of mutating it directly (Single-Writer Law).",
                'architecture.foreignWrite'
            )];
        }

        if ($resource !== null && ! in_array($resource, $declaredResources, true)) {
            $ownerFeature = $owner ?? $feature ?? 'Unknown';
            $suggested = $this->declaredWritersFor($resource);
            $hint = $suggested !== []
                ? 'Delegate to: '.implode(', ', $suggested)
                : "Register a dedicated Actions\\ or Persistence\\ class in app/Features/{$ownerFeature}/feature.json";

            return [$this->error(
                sprintf(
                    "Class '%s' is not a declared writer for '%s'. %s (Single-Writer Law).",
                    $class ?? $relative,
                    $resource,
                    $hint
                ),
                'architecture.writeOwner'
            )];
        }

        if ($resource === null) {
            $prefix = $isDatabase ? 'table:' : 'store:';
            $allowed = array_filter($declaredResources, static fn (string $v): bool => str_starts_with($v, $prefix));
            if ($allowed === []) {
                return [$this->error(
                    "Unregistered storage write in '{$relative}'. Move mutations into a named Actions\\ or Persistence\\ class declared in feature.json (Single-Writer Law).",
                    'architecture.writeOwner'
                )];
            }
        }

        return [];
    }

    /** @return list<string> */
    private function declaredWritersFor(string $resource): array
    {
        $writers = [];
        foreach ($this->catalog['writes'] as $class => $resources) {
            if (in_array($resource, $resources, true)) {
                $writers[] = $class;
            }
        }

        return $writers;
    }

    /** @param list<string> $bases */
    private function isA(Type $type, array $bases): bool
    {
        foreach ($type->getObjectClassNames() as $class) {
            foreach ($bases as $base) {
                if ((new ObjectType($base))->isSuperTypeOf(new ObjectType($class))->yes()) {
                    return true;
                }
            }
        }

        return false;
    }

    private function tableResource(Node\Expr $node): ?string
    {
        while ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall || $node instanceof Node\Expr\StaticCall) {
            if ($node->name instanceof Node\Identifier
                && in_array(strtolower($node->name->toString()), ['table', 'from'], true)
                && isset($node->args[0])
                && $node->args[0]->value instanceof Node\Scalar\String_) {
                return 'table:'.$node->args[0]->value->value;
            }
            if ($node instanceof Node\Expr\StaticCall) {
                break;
            }
            $node = $node->var;
        }

        return null;
    }

    private function storeResource(Node\Expr $node): ?string
    {
        while ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall || $node instanceof Node\Expr\StaticCall) {
            if ($node->name instanceof Node\Identifier
                && in_array(strtolower($node->name->toString()), ['disk', 'store', 'connection'], true)
                && isset($node->args[0])
                && $node->args[0]->value instanceof Node\Scalar\String_) {
                return 'store:'.$node->args[0]->value->value;
            }
            if ($node instanceof Node\Expr\StaticCall) {
                break;
            }
            $node = $node->var;
        }

        return null;
    }

    private function modelResource(string $model): ?string
    {
        if (array_key_exists($model, $this->modelResources)) {
            return $this->modelResources[$model];
        }

        $this->modelResources[$model] = null;
        if (! $this->reflectionProvider->hasClass($model)) {
            return null;
        }

        $file = $this->reflectionProvider->getClass($model)->getFileName();
        if ($file === null || ! is_file($file)) {
            return null;
        }

        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse((string) file_get_contents($file)) ?? [];
        $shortName = substr($model, strrpos($model, '\\') + 1);

        foreach ((new NodeFinder())->findInstanceOf($ast, Node\Stmt\Class_::class) as $class) {
            if ($class->name?->toString() !== $shortName) {
                continue;
            }
            foreach ($class->getProperties() as $property) {
                foreach ($property->props as $prop) {
                    if ($prop->name->toString() === 'table' && $prop->default instanceof Node\Scalar\String_) {
                        return $this->modelResources[$model] = 'table:'.$prop->default->value;
                    }
                }
            }
        }

        return null;
    }

    private function error(string $message, string $identifier): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)->identifier($identifier)->build();
    }
}
