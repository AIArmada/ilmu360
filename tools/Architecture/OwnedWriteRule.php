<?php

declare(strict_types=1);

namespace LaravelAgentArchitecture;

use App\Platform\FeatureCatalog;
use DateTimeImmutable;
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
use JsonException;
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
use RuntimeException;

/** @implements Rule<Node\Expr> */
final class OwnedWriteRule implements Rule
{
    private const FOREIGN_WRITE = 'architecture.foreignWrite';

    private const WRITE_LOCATION = 'architecture.writeLocation';

    /** @var list<string> */

    /** @var list<string> */
    private const WRITE_RULES = [self::FOREIGN_WRITE, self::WRITE_LOCATION];

    /**
     * @var array{
     *     features: array<string, string>,
     *     owners: array<string, string>,
     *     writes: array<string, list<string>>,
     *     aliases: array<string, array<string, string>>,
     *     rootStoreWriters: array<string, array{adapter: 'cache'|'filesystem'|'redis', resource: string, reason: string, expires: string}>
     * }
     */
    private array $catalog;

    /** @var array<string, array<string, array<string, array<string, true>>>> Rule, project-relative file, target, model. */
    private array $writeExceptions = [];

    /** @var array<string, string|null> */
    private array $modelResources = [];

    /** @var array<string, string|null> */
    private array $configuredTables = [];

    public function __construct(private readonly ReflectionProvider $reflectionProvider, private readonly string $projectRoot)
    {
        $this->writeExceptions = $this->loadWriteExceptions($projectRoot);
        $this->catalog = FeatureCatalog::scan($projectRoot);
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
        $storeAdapters = $this->storeAdapterFamilies($type);
        $storeAdapter = count($storeAdapters) === 1 ? $storeAdapters[0] : null;

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
        $store = $storeAdapters !== [];
        if ((! $database || ! in_array($method, $db, true)) && (! $store || ! in_array($method, $stores, true))) {
            return [];
        }

        $file = str_replace('\\', '/', realpath($scope->getFile()) ?: $scope->getFile());
        $root = rtrim(str_replace('\\', '/', realpath($this->projectRoot) ?: $this->projectRoot), '/');
        $relative = str_starts_with($file, $root.'/') ? substr($file, strlen($root) + 1) : $file;
        $exceptionFile = $relative;
        if ($scope->isInTrait()) {
            $traitFile = $scope->getTraitReflection()->getFileName();

            if ($traitFile !== null) {
                $traitFile = str_replace('\\', '/', realpath($traitFile) ?: $traitFile);
                $exceptionFile = str_starts_with($traitFile, $root.'/')
                    ? substr($traitFile, strlen($root) + 1)
                    : $traitFile;
            }
        }

        if ($this->isNonRuntimePath($relative)) {
            return [];
        }
        $feature = preg_match('~^app/Features/([^/]+)/~', $relative, $match) ? $match[1] : null;
        $class = $scope->getClassReflection()?->getName();
        $writerResources = $class === null ? [] : ($this->catalog['writes'][$class] ?? []);
        $rootStoreWriter = $class === null ? null : ($this->catalog['rootStoreWriters'][$class] ?? null);
        if ($rootStoreWriter !== null && $rootStoreWriter['adapter'] === $storeAdapter) {
            $writerResources[] = $rootStoreWriter['resource'];
        }
        $resources = $database ? $this->tableResources($node, $feature, $scope) : [];
        $aliasResource = $this->aliasResource($node, $feature, $scope);
        if ($aliasResource !== null) {
            $resources[] = $aliasResource;
        }
        if ($store && count($writerResources) === 1 && str_starts_with($writerResources[0], 'store:')) {
            $resources[] = $writerResources[0];
        }
        $modelClasses = array_values(array_filter(
            $type->getObjectClassNames(),
            fn (string $model): bool => $this->isA(new ObjectType($model), [Model::class]),
        ));
        $relatedTypes = [
            $type->getTemplateType(Builder::class, 'TModel'),
            $type->getTemplateType(Relation::class, 'TRelatedModel'),
        ];
        foreach ($relatedTypes as $relatedType) {
            foreach ($relatedType->getObjectClassNames() as $model) {
                if ($this->isA(new ObjectType($model), [Model::class])) {
                    $modelClasses[] = $model;
                }
            }
        }

        if ($node instanceof Node\Expr\StaticCall
            && $node->class instanceof Node\Name
            && strtolower($node->class->toString()) === 'parent') {
            $contextClass = $scope->getClassReflection()?->getName();

            if ($contextClass !== null && $this->isA(new ObjectType($contextClass), [Model::class])) {
                $modelClasses = [$contextClass];
            }
        }

        $modelClasses = array_values(array_unique($modelClasses));
        foreach ($modelClasses as $model) {
            $resource = $this->modelResource($model, $feature);

            if ($resource !== null) {
                $resources[] = $resource;
            }
        }
        $resources = array_values(array_unique($resources));

        if ($resources !== []) {
            $errors = [];

            foreach ($resources as $resource) {
                $owner = $this->catalog['owners'][$resource] ?? null;

                // Application infrastructure outside feature ownership is outside this rule's scope.
                if ($feature === null && $owner === null) {
                    continue;
                }
                if ($owner !== null && $owner !== $feature) {
                    array_push($errors, ...$this->violation("{$resource} belongs to {$owner}; call its public API.", self::FOREIGN_WRITE, $exceptionFile, $resource, $modelClasses));

                    continue;
                }
                if (! in_array($resource, $writerResources, true)) {
                    array_push($errors, ...$this->violation(
                        "{$class} is not a declared write action for {$resource}; register it under the owning feature or delegate to one.",
                        self::WRITE_LOCATION,
                        $exceptionFile,
                        $resource,
                        $modelClasses,
                    ));
                }
            }

            return $errors;
        }

        $prefix = $database ? 'table:' : 'store:';
        $receiverTypes = $modelClasses === [] ? 'none' : implode(', ', $modelClasses);

        // A writer's ownership of one resource cannot authorize an
        // unresolved mutation against every other resource.
        return $this->violation(
            "Cannot resolve {$prefix}unknown from receiver model types [{$receiverTypes}]; storage mutations belong in a named owning-feature Actions/Persistence/Contributors class listed in feature.json.",
            self::WRITE_LOCATION,
            $exceptionFile,
            $prefix.'unknown',
            $modelClasses,
        );
    }

    /** @return array<string, array<string, array<string, array<string, true>>>> */
    private function loadWriteExceptions(string $root): array
    {
        $path = rtrim(str_replace('\\', '/', $root), '/').'/tools/architecture.json';
        if (! is_file($path)) {
            throw new RuntimeException("Owned-write exceptions require {$path}.");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unreadable {$path}.");
        }

        try {
            $config = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Invalid {$path}: {$exception->getMessage()}", 0, $exception);
        }

        if (! is_array($config) || ($config['schema'] ?? null) !== 1) {
            throw new RuntimeException("Invalid {$path} schema.");
        }

        if (! is_array($config['root_store_writers'] ?? null)) {
            throw new RuntimeException("Invalid {$path} root store writer registry.");
        }

        $declared = $config['exceptions'] ?? null;
        if (! is_array($declared)) {
            throw new RuntimeException("Invalid {$path} exceptions.");
        }

        $exceptions = [];
        foreach ($declared as $exception) {
            if (! is_array($exception)) {
                throw new RuntimeException("Invalid {$path} exception; expected an object.");
            }
            $rule = $this->exceptionField($exception, 'rule', $path);
            if (! in_array($rule, self::WRITE_RULES, true)) {
                continue;
            }
            $file = $this->exceptionField($exception, 'file', $path);
            $target = $this->exceptionField($exception, 'target', $path);
            $this->exceptionField($exception, 'reason', $path);
            $this->exceptionField($exception, 'owner', $path);
            $expires = $this->exceptionField($exception, 'expires', $path);
            $model = $exception['model'] ?? null;
            if (strpbrk($file.$target, '*?') !== false) {
                throw new RuntimeException("Invalid {$path} exception: exact file/target (no wildcard) required.");
            }
            if (str_starts_with($target, 'table:')
                && (! is_string($model) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $model))) {
                throw new RuntimeException("Table write exceptions require an exact model class: {$file} {$target}");
            }
            if ($target === 'store:unknown') {
                throw new RuntimeException("Unknown store exceptions are not allowed: {$file} {$target}");
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $expires);
            if (! $date || $date->format('Y-m-d') !== $expires || $expires < gmdate('Y-m-d')) {
                throw new RuntimeException("Invalid/expired exception: {$file} {$target}");
            }
            if (in_array($rule, self::WRITE_RULES, true)) {
                if (is_string($model)) {
                    $exceptions[$rule][$file][$target][$model] = true;
                }
            }
        }

        return $exceptions;
    }

    /** @param array<mixed> $exception */
    private function exceptionField(array $exception, string $field, string $path): string
    {
        $value = $exception[$field] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("Invalid {$path} exception: nonempty {$field} required.");
        }

        return $value;
    }

    /**
     * @param  list<string>  $modelClasses
     * @return list<IdentifierRuleError>
     */
    private function violation(string $message, string $identifier, string $file, string $target, array $modelClasses = []): array
    {
        $exceptionModels = $this->writeExceptions[$identifier][$file][$target] ?? [];
        if ($modelClasses === [] && isset($exceptionModels['Illuminate\\Database\\Query\\Builder'])) {
            return [];
        }
        if (count($modelClasses) === 1 && isset($exceptionModels[$modelClasses[0]])) {
            return [];
        }

        return [$this->error($message, $identifier)];
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

    /** @return list<'cache'|'filesystem'|'redis'> */
    private function storeAdapterFamilies(Type $type): array
    {
        $families = [];

        if ($this->isA($type, [Repository::class, Store::class, Cache::class])) {
            $families[] = 'cache';
        }

        if ($this->isA($type, [Filesystem::class, Storage::class])) {
            $families[] = 'filesystem';
        }

        if ($this->isA($type, [Connection::class, Redis::class])) {
            $families[] = 'redis';
        }

        return $families;
    }

    /** @return list<string> */
    private function tableResources(Node\Expr $node, ?string $feature, Scope $scope): array
    {
        while ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall || $node instanceof Node\Expr\StaticCall) {
            if ($node->name instanceof Node\Identifier && in_array(strtolower($node->name->toString()), ['table', 'from'], true)
                && isset($node->args[0]) && $node->args[0]->value instanceof Node\Expr) {
                $tables = $this->tableArguments($node->args[0]->value, $scope);

                if ($tables !== []) {
                    return array_map(static fn (string $table): string => 'table:'.$table, $tables);
                }
            }
            if ($node instanceof Node\Expr\StaticCall) {
                // Chained alias writes, e.g. a morph model helper query followed by ->where(...)->delete().
                $aliasResource = $this->aliasResource($node, $feature, $scope);
                if ($aliasResource !== null) {
                    return [$aliasResource];
                }
                break;
            }
            $node = $node->var;
        }

        return [];
    }

    /** @return list<string> */
    private function tableArguments(Node\Expr $expression, Scope $scope): array
    {
        $literal = $this->literalTable($expression);

        if ($literal !== null) {
            return [$literal];
        }

        $constantStrings = $scope->getType($expression)->getConstantStrings();

        return array_values(array_unique(array_map(
            static fn (\PHPStan\Type\Constant\ConstantStringType $table): string => $table->getValue(),
            $constantStrings,
        )));
    }

    private function aliasResource(Node\Expr $node, ?string $feature, Scope $scope): ?string
    {
        if ($feature === null || ! $node instanceof Node\Expr\StaticCall) {
            return null;
        }
        if (! $node->class instanceof Node\Name || ! $node->name instanceof Node\Identifier) {
            return null;
        }
        if (! in_array(strtolower($node->name->toString()), ['query', 'new'], true)) {
            return null;
        }
        $class = $scope->resolveName($node->class);

        if (! str_starts_with($class, 'App\\Features\\'.$feature.'\\Support\\')
            || ! str_ends_with($class, 'Model')) {
            return null;
        }
        $argument = $node->args[0]->value ?? null;
        if (! $argument instanceof Node\Scalar\String_) {
            return null;
        }

        return $this->catalog['aliases'][$feature][$argument->value] ?? null;
    }

    private function modelResource(string $model, ?string $feature): ?string
    {
        $cacheKey = ($feature ?? '').'|'.$model;

        if (array_key_exists($cacheKey, $this->modelResources)) {
            return $this->modelResources[$cacheKey];
        }
        $this->modelResources[$cacheKey] = null;

        if (! $this->reflectionProvider->hasClass($model)) {
            return null;
        }

        $class = $this->reflectionProvider->getClass($model);

        while ($class !== null) {
            $file = $class->getFileName();

            if ($file !== null && is_file($file)) {
                $source = file_get_contents($file);
                $ast = $source === false
                    ? []
                    : ((new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? []);

                foreach ((new NodeFinder)->findInstanceOf($ast, Node\Stmt\Class_::class) as $classNode) {
                    if ($classNode->name?->toString() !== substr($class->getName(), strrpos($class->getName(), '\\') + 1)) {
                        continue;
                    }

                    foreach ($classNode->getProperties() as $property) {
                        foreach ($property->props as $prop) {
                            if ($prop->name->toString() === 'table' && $prop->default instanceof Node\Scalar\String_) {
                                return $this->modelResources[$cacheKey] = 'table:'.$prop->default->value;
                            }
                        }
                    }

                    foreach ($classNode->getMethods() as $method) {
                        if (strtolower($method->name->toString()) !== 'gettable') {
                            continue;
                        }

                        foreach ($method->stmts ?? [] as $statement) {
                            if ($statement instanceof Node\Stmt\Return_ && $statement->expr instanceof Node\Expr) {
                                $table = $this->literalTable($statement->expr);

                                if ($table !== null) {
                                    return $this->modelResources[$cacheKey] = 'table:'.$table;
                                }
                            }
                        }
                    }
                }
            }

            $class = $class->getParentClass();
        }

        return null;
    }

    private function literalTable(Node\Expr $expression): ?string
    {
        if ($expression instanceof Node\Scalar\String_) {
            return $expression->value;
        }

        if ($expression instanceof Node\Expr\Cast\String_) {
            return $this->literalTable($expression->expr);
        }

        if ($expression instanceof Node\Expr\Ternary
            && $expression->if === null
            && $expression->cond instanceof Node\Expr\FuncCall
            && $expression->cond->name instanceof Node\Name
            && strtolower($expression->cond->name->toString()) === 'config'
        ) {
            return $this->literalTable($expression->cond) ?? $this->literalTable($expression->else);
        }

        if (! $expression instanceof Node\Expr\FuncCall || ! $expression->name instanceof Node\Name) {
            return null;
        }

        $function = strtolower($expression->name->toString());

        if ($function === 'env') {
            $default = $expression->args[1]->value ?? null;

            return $default instanceof Node\Expr ? $this->literalTable($default) : null;
        }

        if ($function !== 'config') {
            return null;
        }

        $key = $expression->args[0]->value ?? null;

        if ($key instanceof Node\Scalar\String_) {
            $configured = $this->configuredTable($key->value);

            if ($configured !== null) {
                return $configured;
            }
        }

        $default = $expression->args[1]->value ?? null;

        return $default instanceof Node\Expr ? $this->literalTable($default) : null;
    }

    private function configuredTable(string $key): ?string
    {
        if (array_key_exists($key, $this->configuredTables)) {
            return $this->configuredTables[$key];
        }

        $this->configuredTables[$key] = null;
        $segments = explode('.', $key);
        $configFile = array_shift($segments);

        if (! is_string($configFile) || ! preg_match('/^[A-Za-z0-9_-]+$/D', $configFile) || $segments === []) {
            return null;
        }

        $path = rtrim(str_replace('\\', '/', $this->projectRoot), '/').'/config/'.$configFile.'.php';

        if (! is_file($path)) {
            return null;
        }

        $source = file_get_contents($path);

        if ($source === false) {
            return null;
        }

        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
        $value = null;

        foreach ($ast as $node) {
            if ($node instanceof Node\Stmt\Return_ && $node->expr instanceof Node\Expr) {
                $value = $node->expr;

                break;
            }
        }

        foreach ($segments as $segment) {
            if (! $value instanceof Node\Expr\Array_) {
                return null;
            }

            $next = null;

            foreach ($value->items as $item) {
                if ($item instanceof Node\Expr\ArrayItem
                    && $item->key instanceof Node\Scalar\String_
                    && $item->key->value === $segment) {
                    $next = $item->value;

                    break;
                }
            }

            if (! $next instanceof Node\Expr) {
                return null;
            }

            $value = $next;
        }

        return $this->configuredTables[$key] = $this->literalTable($value);
    }

    private function error(string $message, string $identifier): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)->identifier($identifier)->build();
    }
}
