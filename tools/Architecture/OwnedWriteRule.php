<?php

declare(strict_types=1);

namespace LaravelAgentArchitecture;

use App\Platform\FeatureCatalog;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
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
    /** @var array{features: array<string, string>, owners: array<string, string>, writes: array<string, list<string>>} */
    private array $catalog;

    /** @var array<class-string, string|null> */
    private array $modelResources = [];

    public function __construct(private readonly ReflectionProvider $reflectionProvider, private readonly string $projectRoot)
    {
        $this->catalog = FeatureCatalog::load($projectRoot);
    }

    public function getNodeType(): string
    {
        return Node\Expr::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node instanceof Node\Expr\MethodCall && ! $node instanceof Node\Expr\NullsafeMethodCall && ! $node instanceof Node\Expr\StaticCall) {
            return [];
        }
        if (! $node->name instanceof Node\Identifier) {
            return []; // Unknown/dynamic calls belong to the project's ordinary type analysis.
        }
        $method = strtolower($node->name->toString());
        if ($node instanceof Node\Expr\StaticCall) {
            $type = $node->class instanceof Node\Name
                ? new ObjectType($scope->resolveName($node->class)) : $scope->getType($node->class);
        } else {
            $type = $scope->getType($node->var);
        }

        $db = ['save', 'savequietly', 'saveorfail', 'push', 'pushquietly', 'create', 'createquietly', 'forcecreate', 'forcecreatequietly',
            'update', 'updatequietly', 'updateorcreate', 'firstorcreate', 'createorfirst', 'incrementorcreate',
            'delete', 'deletequietly', 'destroy', 'forcedestroy', 'forcedelete', 'restore', 'restoreorcreate', 'createorrestore',
            'increment', 'decrement', 'incrementquietly', 'decrementquietly', 'touch', 'touchquietly', 'touchowners',
            'incrementeach', 'decrementeach', 'insert', 'insertgetid', 'insertorignore', 'insertusing', 'upsert',
            'truncate', 'attach', 'detach', 'sync', 'syncwithoutdetaching', 'syncwithpivotvalues', 'toggle', 'updateexistingpivot',
            'savemany', 'savemanyquietly', 'createmany', 'createmanyquietly', 'updatefrom', 'statement', 'unprepared', 'affectingstatement'];
        $stores = ['put', 'putfile', 'putfileas', 'set', 'setex', 'setnx', 'mset', 'hset', 'hmset', 'hdel', 'del',
            'rpush', 'lpush', 'lpop', 'rpop', 'sadd', 'srem', 'zadd', 'zrem', 'write', 'writestream',
            'delete', 'deletedirectory', 'makedirectory', 'copy', 'move', 'append', 'prepend',
            'forget', 'flush', 'forever', 'remember', 'rememberforever', 'add', 'increment', 'decrement', 'pull'];
        $db = array_map(strtolower(...), $db);
        $database = $this->isA($type, [Model::class, Builder::class,
            Relation::class, \Illuminate\Database\Query\Builder::class,
            ConnectionInterface::class, DB::class]);
        $store = $this->isA($type, [Repository::class, Store::class,
            Filesystem::class, Connection::class,
            Cache::class, Storage::class, Redis::class]);
        if ((! $database || ! in_array($method, $db, true)) && (! $store || ! in_array($method, $stores, true))) {
            return [];
        }

        $file = str_replace('\\', '/', realpath($scope->getFile()) ?: $scope->getFile());
        $root = rtrim(str_replace('\\', '/', realpath($this->projectRoot) ?: $this->projectRoot), '/');
        $relative = str_starts_with($file, $root.'/') ? substr($file, strlen($root) + 1) : $file;
        if ($this->isNonRuntimePath($relative)) {
            return [];
        }
        $feature = preg_match('~^app/Features/([^/]+)/~', $relative, $match) ? $match[1] : null;
        $class = $scope->getClassReflection()?->getName();
        $resources = $class === null ? [] : ($this->catalog['writes'][$class] ?? []);
        $resource = $database ? $this->tableResource($node) : null;
        $modelClasses = $type->getObjectClassNames();
        $relatedTypes = [
            $type->getTemplateType(Builder::class, 'TModel'),
            $type->getTemplateType(Relation::class, 'TRelatedModel'),
        ];
        foreach ($relatedTypes as $relatedType) {
            foreach ($relatedType->getObjectClassNames() as $model) {
                $modelClasses[] = $model;
            }
        }
        foreach ($modelClasses as $model) {
            if ($this->isA(new ObjectType($model), [Model::class])) {
                $resource ??= $this->modelResource($model);
            }
        }
        $owner = $resource === null ? null : ($this->catalog['owners'][$resource] ?? null);
        // Application infrastructure outside feature ownership is outside this rule's scope.
        if ($feature === null && $owner === null) {
            return [];
        }
        if ($owner !== null && $owner !== $feature) {
            return [$this->error("{$resource} belongs to {$owner}; call its public API.", 'architecture.foreignWrite')];
        }
        if ($resource !== null && ! in_array($resource, $resources, true)) {
            return [$this->error("{$class} is not a declared write action for {$resource}; register it under the owning feature or delegate to one.", 'architecture.writeLocation')];
        }
        if ($resource === null) {
            $prefix = $database ? 'table:' : 'store:';
            $allowed = array_filter($resources, static fn (string $value): bool => str_starts_with($value, $prefix));
            if ($allowed === []) {
                return [$this->error('Storage mutations belong in a named owning-feature Actions/Persistence class listed in feature.json.', 'architecture.writeLocation')];
            }
        }

        return [];
    }

    private function isNonRuntimePath(string $relative): bool
    {
        return preg_match(
            '~^(?:tests/|database/(?:seeders|factories|migrations)/|app/Features/[A-Z][A-Za-z0-9]*/Database/(?:Seeders|Factories|Migrations)/)~',
            $relative,
        ) === 1;
    }

    /** @param list<class-string> $bases */
    private function isA(Type $type, array $bases): bool
    {
        // Nullable/union receivers may contain a known storage type; unrelated types remain unaffected.
        foreach ($type->getObjectClassNames() as $class) {
            foreach ($bases as $base) {
                if (new ObjectType($base)->isSuperTypeOf(new ObjectType($class))->yes()) {
                    return true;
                }
            }
        }

        return false;
    }

    private function tableResource(Node\Expr $node): ?string
    {
        while ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall || $node instanceof Node\Expr\StaticCall) {
            if ($node->name instanceof Node\Identifier && in_array(strtolower($node->name->toString()), ['table', 'from'], true)
                && isset($node->args[0]) && $node->args[0]->value instanceof Node\Scalar\String_) {
                return 'table:'.$node->args[0]->value->value;
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
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse(file_get_contents($file)) ?? [];
        foreach ((new NodeFinder)->findInstanceOf($ast, Node\Stmt\Class_::class) as $class) {
            if ($class->name?->toString() !== substr($model, strrpos($model, '\\') + 1)) {
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
