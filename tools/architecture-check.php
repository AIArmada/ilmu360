<?php

declare(strict_types=1);

// Bounded AST dependency and ownership guard; not a formal whole-program
// proof. Writes are checked by the separate PHPStan rule. Does not boot
// Laravel or connect to a database.
use App\Platform\FeatureCatalog;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode as PhpDocTypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer as PhpDocLexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

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
$phpDocConfig = new ParserConfig([]);
$phpDocConstExprParser = new ConstExprParser($phpDocConfig);
$phpDocParser = new PhpDocParser($phpDocConfig, new TypeParser($phpDocConfig, $phpDocConstExprParser), $phpDocConstExprParser);
$phpDocLexer = new PhpDocLexer($phpDocConfig);
$phpDocReturnType = static function (string $docComment) use ($phpDocParser, $phpDocLexer): ?string {
    if ($docComment === '') {
        return null;
    }

    try {
        $phpDoc = $phpDocParser->parse(new TokenIterator($phpDocLexer->tokenize($docComment)));
    } catch (Throwable) {
        return null;
    }

    foreach ($phpDoc->children as $child) {
        if ($child instanceof PhpDocTagNode && $child->name === '@return' && $child->value instanceof ReturnTagValueNode) {
            return (string) $child->value->type;
        }
    }

    return null;
};

try {
    $config = json_decode(file_get_contents($root.'/tools/architecture.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($config['schema'] ?? null) !== 1 || ! is_array($config['exceptions'] ?? null)
        || ! is_array($config['root_store_writers'] ?? null)) {
        throw new RuntimeException('Invalid tools/architecture.json schema.');
    }
    $catalog = FeatureCatalog::scan($root);
    $owners = $catalog['owners'];
    $features = $catalog['features'];
    $readAdapters = $config['read_adapters'] ?? [];
    if (! is_array($readAdapters)) {
        throw new RuntimeException('read_adapters must be a map of exact file paths.');
    }
    $readAdapterUsage = [];
    foreach ($readAdapters as $adapterFile => $adapter) {
        if (! is_string($adapterFile) || strpbrk($adapterFile, '*?') !== false
            || ! is_array($adapter)
            || ! is_string($adapter['owner'] ?? null) || trim($adapter['owner']) === ''
            || ! is_string($adapter['reason'] ?? null) || trim($adapter['reason']) === ''
            || ! is_string($adapter['removal_task'] ?? null) || trim($adapter['removal_task']) === ''
            || ! is_string($adapter['expires'] ?? null)) {
            throw new RuntimeException('Read adapters require exact file, owner, reason, removal_task and expires.');
        }
        $expiry = DateTimeImmutable::createFromFormat('!Y-m-d', $adapter['expires']);
        if (! $expiry || $expiry->format('Y-m-d') !== $adapter['expires'] || $adapter['expires'] < gmdate('Y-m-d')) {
            throw new RuntimeException('Invalid/expired read adapter: '.$adapterFile);
        }
        $readAdapterUsage[$adapterFile] = false;
    }

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

    $nativeTypeNames = static function (?Node $type) use (&$nativeTypeNames): array {
        if ($type instanceof Node\Name) {
            $resolved = $type->getAttribute('resolvedName');

            return [strtolower(ltrim($resolved instanceof Node\Name ? $resolved->toString() : $type->toString(), '\\'))];
        }

        if ($type instanceof Node\Identifier) {
            return [strtolower($type->toString())];
        }

        if ($type instanceof Node\NullableType) {
            return $nativeTypeNames($type->type);
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $names = [];

            foreach ($type->types as $innerType) {
                array_push($names, ...$nativeTypeNames($innerType));
            }

            return $names;
        }

        return [];
    };
    $phpDocTypeNames = static function (string $type, array $imports, string $namespace): array {
        preg_match_all('~\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*~', $type, $matches);
        $names = [];

        foreach ($matches[0] as $rawName) {
            $isAbsolute = str_starts_with($rawName, '\\');
            $name = trim($rawName, '\\');
            $segments = explode('\\', $name);
            $firstSegment = $segments[0];

            if ($isAbsolute) {
                $resolved = $name;
            } elseif (isset($imports[strtolower($firstSegment)])) {
                $resolved = $imports[strtolower($firstSegment)].(isset($segments[1]) ? '\\'.implode('\\', array_slice($segments, 1)) : '');
            } elseif (count($segments) > 1 || in_array(strtolower($name), ['array', 'list', 'iterable', 'collection', 'mixed', 'object', 'null', 'void', 'never', 'int', 'integer', 'string', 'bool', 'boolean', 'float', 'callable', 'resource', 'scalar', 'self', 'static', 'parent'], true)) {
                $resolved = $name;
            } else {
                $resolved = $namespace === '' ? $name : $namespace.'\\'.$name;
            }

            $names[] = strtolower($resolved);
        }

        return array_values(array_unique($names));
    };
    $ormReturnShape = static function (array $typeNames): string {
        $hasCollection = false;
        $hasEloquentCollection = false;
        $hasModel = false;
        $hasOpaque = false;

        foreach ($typeNames as $typeName) {
            if (in_array($typeName, ['object', 'mixed'], true)) {
                $hasOpaque = true;

                continue;
            }

            $lastSeparator = strrpos($typeName, '\\');
            $shortName = strtolower($lastSeparator === false ? $typeName : substr($typeName, $lastSeparator + 1));

            if (str_ends_with($shortName, 'builder') || $shortName === 'relation' || str_contains($typeName, '\\database\\eloquent\\relations\\')) {
                return 'builder';
            }

            if (in_array($shortName, ['array', 'list', 'iterable', 'eloquentcollection', 'collection'], true)
                || str_contains($typeName, '\\database\\eloquent\\collection')
                || str_contains($shortName, 'paginator')) {
                $hasCollection = true;
            }

            if ($shortName === 'eloquentcollection' || str_contains($typeName, '\\database\\eloquent\\collection')) {
                $hasEloquentCollection = true;
            }

            if ($typeName === 'illuminate\\database\\eloquent\\model'
                || (str_starts_with($typeName, 'app\\') && str_contains($typeName, '\\models\\'))
                || (str_starts_with($typeName, 'aiarmada\\') && str_contains($typeName, '\\models\\'))) {
                $hasModel = true;
            }
        }

        if ($hasCollection && ($hasModel || $hasEloquentCollection || array_any($typeNames, static fn (string $typeName): bool => str_contains($typeName, 'paginator')))) {
            return 'collection';
        }

        if ($hasCollection) {
            return 'value';
        }

        if ($hasOpaque) {
            return 'opaque';
        }

        return $hasModel ? 'model' : 'value';
    };

    // Business-query escape detection needs the published ORM surface before
    // the per-file pass. Record every Api/Orm method's return shape so a
    // foreign consumer that receives a builder can be rejected even when the
    // port is annotated @internal with an accurate Consumers list.
    $ormContracts = [];
    foreach ($paths as $relative => $path) {
        if (preg_match('~^app/Features/([^/]+)/Api/Orm/~', $relative, $ownerMatch) !== 1) {
            continue;
        }
        $ormAst = $parser->parse((string) file_get_contents($path)) ?? [];
        $ormTraverser = new NodeTraverser;
        $ormTraverser->addVisitor(new NameResolver);
        $ormAst = $ormTraverser->traverse($ormAst);
        $namespaceName = '';
        $imports = [];

        foreach ($ormAst as $rootNode) {
            if ($rootNode instanceof Node\Stmt\Namespace_) {
                $namespaceName = $rootNode->name?->toString() ?? '';
            }
        }

        foreach ($finder->findInstanceOf($ormAst, Node\Stmt\UseUse::class) as $use) {
            $resolved = $use->name->getAttribute('resolvedName');
            $importName = ltrim($resolved instanceof Node\Name ? $resolved->toString() : $use->name->toString(), '\\');
            $importSegments = explode('\\', $importName);
            $alias = $use->alias?->toString() ?? end($importSegments);
            $imports[strtolower($alias)] = strtolower($importName);
        }

        foreach ($finder->findInstanceOf($ormAst, Node\Stmt\ClassLike::class) as $ormClass) {
            if ($ormClass->name === null) {
                continue;
            }
            $declared = isset($ormClass->namespacedName) ? $ormClass->namespacedName->toString() : $ormClass->name->toString();
            $ormContracts[$declared] = ['owner' => $ownerMatch[1], 'file' => $relative, 'methods' => []];
            foreach ($ormClass->getMethods() as $ormMethod) {
                if (! $ormMethod->isPublic()) {
                    continue;
                }
                $ormDoc = $ormMethod->getDocComment()?->getText() ?? '';
                $docReturn = $phpDocReturnType($ormDoc) ?? '';

                if ($docReturn === '' && preg_match('~@return\s+([^\s*]+)~', $ormDoc, $docMatch) === 1) {
                    // Fallback for malformed PHPDoc that the parser rejects.
                    $docReturn = trim($docMatch[1]);
                }

                $typeNames = $nativeTypeNames($ormMethod->returnType);

                if ($docReturn !== '') {
                    $docTypes = $phpDocTypeNames($docReturn, $imports, $namespaceName);
                    array_push($typeNames, ...$docTypes);
                }

                if ($ormMethod->returnType === null && strtolower($ormMethod->name->toString()) !== '__construct') {
                    $report('orm-opaque-return', $relative, $ormMethod->getStartLine(), $ormMethod->name->toString(), 'Declare a concrete native return type for every public ORM port method; use PHPDoc for generic details.');
                }

                $returnShape = $ormReturnShape($typeNames);

                if ($returnShape === 'opaque') {
                    $report('orm-opaque-return', $relative, $ormMethod->getStartLine(), $ormMethod->name->toString(), 'Name the concrete owner model, Eloquent collection/builder or immutable value; opaque object/mixed is not an integration seam.');
                }

                $nativeUnionShapes = $ormMethod->returnType instanceof Node\UnionType
                    ? array_values(array_unique(array_map(
                        static fn (Node $type): string => $ormReturnShape($nativeTypeNames($type)),
                        $ormMethod->returnType->types,
                    )))
                    : [];
                $docUnionShapes = [];

                if (str_contains($docReturn, '|')) {
                    foreach (explode('|', $docReturn) as $unionType) {
                        $docUnionShapes[] = $ormReturnShape($phpDocTypeNames($unionType, $imports, $namespaceName));
                    }
                    $docUnionShapes = array_values(array_unique($docUnionShapes));
                }

                if (in_array($returnShape, ['builder', 'model', 'collection'], true)
                    && (count($nativeUnionShapes) > 1 || count($docUnionShapes) > 1)) {
                    $report('orm-opaque-return', $relative, $ormMethod->getStartLine(), $ormMethod->name->toString(), 'Do not union mutable ORM values with other return shapes; split the contract into explicit methods.');
                }
                $preservesBuilderArgument = null;
                if (preg_match('~@template\s+([A-Za-z_][A-Za-z0-9_]*)\s+of\s+([^\n*]+)~', $ormDoc, $templateMatch) === 1
                    && preg_match('~@param\s+'.preg_quote($templateMatch[1], '~').'\s+\$([A-Za-z_][A-Za-z0-9_]*)~', $ormDoc) === 1
                    && preg_match('~@return\s+'.preg_quote($templateMatch[1], '~').'(?:\s|\*|$)~', $ormDoc) === 1
                    && preg_match('~builder~i', $templateMatch[2]) === 1) {
                    preg_match('~@param\s+'.preg_quote($templateMatch[1], '~').'\s+\$([A-Za-z_][A-Za-z0-9_]*)~', $ormDoc, $templateParameterMatch);

                    foreach ($ormMethod->params as $parameterIndex => $parameter) {
                        if ($parameter->var instanceof Node\Expr\Variable
                            && $parameter->var->name === ($templateParameterMatch[1] ?? null)
                            && $ormReturnShape($nativeTypeNames($parameter->type)) === 'builder') {
                            $preservesBuilderArgument = $parameterIndex;
                            break;
                        }
                    }
                }

                $ormContracts[$declared]['methods'][$ormMethod->name->toString()] = [
                    'shape' => $returnShape,
                    'preserves-builder-argument' => $preservesBuilderArgument,
                    'line' => $ormMethod->getStartLine(),
                ];
            }
        }
    }

    $ormBuilderTerminals = [
        'count', 'cursor', 'cursorpaginate', 'doesntexist', 'exists', 'find', 'findorfail',
        'first', 'firstorfail', 'get', 'lazy', 'lazybyid', 'max', 'min', 'paginate',
        'pluck', 'simplepaginate', 'sole', 'sum', 'value', 'avg', 'tosql', 'getbindings',
    ];
    $ormBuilderTerminalShapes = [
        'cursor' => 'collection',
        'cursorpaginate' => 'collection',
        'find' => 'model',
        'findorfail' => 'model',
        'first' => 'model',
        'firstorfail' => 'model',
        'get' => 'collection',
        'lazy' => 'collection',
        'lazybyid' => 'collection',
        'paginate' => 'collection',
        'simplepaginate' => 'collection',
        'sole' => 'model',
    ];
    $ormModelMutators = [
        'decrement', 'delete', 'fill', 'forceDelete', 'forceFill', 'increment', 'push',
        'restore', 'save', 'saveQuietly', 'setAttribute', 'setRelation', 'setRawAttributes',
        'touch', 'unsetRelation', 'update', 'updateQuietly',
    ];
    $ormCollectionMutators = ['forget', 'pop', 'prepend', 'push', 'put', 'shift', 'splice', 'transform'];
    $analyzeOrmConsumer = static function (string $relative, array $ast, ?string $consumerFeature, string $source) use (
        $ormContracts,
        $nativeTypeNames,
        $report,
        $finder,
        $ormBuilderTerminals,
        $ormBuilderTerminalShapes,
        $ormModelMutators,
        $ormCollectionMutators,
    ): void {
        $foreignMethods = [];

        foreach ($ormContracts as $contractName => $contract) {
            if ($contract['owner'] === $consumerFeature) {
                continue;
            }

            $contractSegments = explode('\\', $contractName);
            $contractShort = end($contractSegments);

            if (! str_contains($source, $contractShort) && ! str_contains($source, $contractName)) {
                continue;
            }

            foreach ($contract['methods'] as $methodName => $methodMeta) {
                if (in_array($methodMeta['shape'], ['builder', 'model', 'collection'], true)) {
                    $foreignMethods[$contractName][$methodName] = $methodMeta;
                }
            }
        }

        if ($foreignMethods === []) {
            return;
        }

        $contractForClassName = static function (Node\Name $name) use ($ormContracts): ?string {
            $resolved = $name->getAttribute('resolvedName');
            $className = ltrim($resolved instanceof Node\Name ? $resolved->toString() : $name->toString(), '\\');

            return isset($ormContracts[$className]) ? $className : null;
        };
        $classNameForScope = static function (Node $scope): ?string {
            $parent = $scope->getAttribute('parent');

            while ($parent instanceof Node) {
                if ($parent instanceof Node\Stmt\ClassLike) {
                    return isset($parent->namespacedName)
                        ? $parent->namespacedName->toString()
                        : $parent->name?->toString();
                }

                $parent = $parent->getAttribute('parent');
            }

            return null;
        };
        $belongsToScope = static function (Node $node, Node $scope): bool {
            $parent = $node->getAttribute('parent');

            while ($parent instanceof Node) {
                if ($parent === $scope) {
                    return true;
                }

                if ($parent instanceof Node\Stmt\ClassMethod
                    || $parent instanceof Node\Stmt\Function_
                    || $parent instanceof Node\Expr\Closure
                    || $parent instanceof Node\Expr\ArrowFunction) {
                    return false;
                }

                $parent = $parent->getAttribute('parent');
            }

            return false;
        };
        $findInScope = static function (Node $scope, string $nodeType) use ($finder, $belongsToScope): array {
            return array_values(array_filter(
                $finder->find(
                    $scope,
                    static fn (Node $node): bool => is_a($node, $nodeType),
                ),
                static fn (Node $node): bool => $belongsToScope($node, $scope),
            ));
        };
        $classForExpression = static function (?Node $expression, array $variableContracts, array $propertyContracts) use (&$classForExpression, $contractForClassName): ?string {
            if ($expression instanceof Node\Expr\Variable && is_string($expression->name)) {
                return $variableContracts[$expression->name] ?? null;
            }

            if ($expression instanceof Node\Expr\PropertyFetch
                && $expression->var instanceof Node\Expr\Variable
                && $expression->var->name === 'this'
                && $expression->name instanceof Node\Identifier) {
                return $propertyContracts[$expression->name->toString()] ?? null;
            }

            if ($expression instanceof Node\Expr\FuncCall
                && $expression->name instanceof Node\Name
                && in_array(strtolower($expression->name->toString()), ['app', 'resolve'], true)) {
                $classConstant = $expression->args[0]->value ?? null;

                if ($classConstant instanceof Node\Expr\ClassConstFetch
                    && $classConstant->name instanceof Node\Identifier
                    && strtolower($classConstant->name->toString()) === 'class'
                    && $classConstant->class instanceof Node\Name) {
                    return $contractForClassName($classConstant->class);
                }
            }

            if ($expression instanceof Node\Expr\MethodCall
                && $expression->name instanceof Node\Identifier
                && strtolower($expression->name->toString()) === 'make') {
                $classConstant = $expression->args[0]->value ?? null;

                if ($classConstant instanceof Node\Expr\ClassConstFetch
                    && $classConstant->name instanceof Node\Identifier
                    && strtolower($classConstant->name->toString()) === 'class'
                    && $classConstant->class instanceof Node\Name) {
                    return $contractForClassName($classConstant->class);
                }
            }

            if ($expression instanceof Node\Expr\Assign) {
                return $classForExpression($expression->expr, $variableContracts, $propertyContracts);
            }

            return null;
        };
        $propertyContracts = [];

        foreach ($finder->findInstanceOf($ast, Node\Stmt\Property::class) as $property) {
            foreach ($nativeTypeNames($property->type) as $typeName) {
                foreach (array_keys($ormContracts) as $contractName) {
                    if (strcasecmp($typeName, $contractName) !== 0) {
                        continue;
                    }

                    foreach ($property->props as $propertyNode) {
                        $propertyContracts[$propertyNode->name->toString()] = $contractName;
                    }
                }
            }
        }

        foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $method) {
            if (strtolower($method->name->toString()) !== '__construct') {
                continue;
            }

            foreach ($method->params as $parameter) {
                if ($parameter->flags === 0 || ! $parameter->var instanceof Node\Expr\Variable || ! is_string($parameter->var->name)) {
                    continue;
                }

                foreach ($nativeTypeNames($parameter->type) as $typeName) {
                    foreach (array_keys($ormContracts) as $contractName) {
                        if (strcasecmp($typeName, $contractName) === 0) {
                            $propertyContracts[$parameter->var->name] = $contractName;
                        }
                    }
                }
            }
        }

        $expressionShape = static function (?Node $expression, array $variableShapes, array $propertyShapes, array $variableContracts, array $helperShapes, ?string $scopeClass) use (
            &$expressionShape,
            $classForExpression,
            $foreignMethods,
            $ormBuilderTerminals,
            $ormBuilderTerminalShapes,
        ): ?string {
            if ($expression instanceof Node\Expr\Variable && is_string($expression->name)) {
                return $variableShapes[$expression->name] ?? null;
            }

            if ($expression instanceof Node\Expr\PropertyFetch
                && $expression->var instanceof Node\Expr\Variable
                && $expression->var->name === 'this'
                && $expression->name instanceof Node\Identifier) {
                return $propertyShapes[$expression->name->toString()] ?? null;
            }

            if (! $expression instanceof Node\Expr\MethodCall) {
                if ($expression instanceof Node\Expr\Ternary) {
                    return $expressionShape($expression->if, $variableShapes, $propertyShapes, $variableContracts, $helperShapes, $scopeClass)
                        ?? $expressionShape($expression->else, $variableShapes, $propertyShapes, $variableContracts, $helperShapes, $scopeClass);
                }

                if ($expression instanceof Node\Expr\BinaryOp\Coalesce) {
                    return $expressionShape($expression->left, $variableShapes, $propertyShapes, $variableContracts, $helperShapes, $scopeClass)
                        ?? $expressionShape($expression->right, $variableShapes, $propertyShapes, $variableContracts, $helperShapes, $scopeClass);
                }

                return null;
            }

            $methodName = $expression->name instanceof Node\Identifier ? $expression->name->toString() : null;
            $contractName = $classForExpression($expression->var, $variableContracts, $propertyShapes);

            if ($contractName !== null && $methodName !== null
                && isset($foreignMethods[$contractName][$methodName])) {
                $methodMeta = $foreignMethods[$contractName][$methodName];

                if ($methodMeta['preserves-builder-argument'] !== null) {
                    $preservedArgument = $expression->args[$methodMeta['preserves-builder-argument']]->value ?? null;

                    return $expressionShape($preservedArgument, $variableShapes, $propertyShapes, $variableContracts, $helperShapes, $scopeClass);
                }

                return $methodMeta['shape'];
            }

            if ($expression->var instanceof Node\Expr\Variable
                && $expression->var->name === 'this'
                && $methodName !== null
                && $scopeClass !== null
                && isset($helperShapes[$scopeClass.'::'.$methodName])) {
                return $helperShapes[$scopeClass.'::'.$methodName];
            }

            $receiverShape = $expressionShape($expression->var, $variableShapes, $propertyShapes, $variableContracts, $helperShapes, $scopeClass);

            if ($receiverShape === 'builder') {
                $normalizedMethodName = strtolower((string) $methodName);

                if (isset($ormBuilderTerminalShapes[$normalizedMethodName])) {
                    return $ormBuilderTerminalShapes[$normalizedMethodName];
                }

                if (in_array($normalizedMethodName, $ormBuilderTerminals, true)) {
                    return null;
                }

                return 'builder';
            }

            if ($receiverShape === 'model' && $methodName !== null
                && in_array(strtolower($methodName), ['fill', 'forcefill', 'setattribute', 'setrelation', 'setrawattributes'], true)) {
                return 'model';
            }

            if ($receiverShape === 'collection' && $methodName !== null
                && in_array(strtolower($methodName), ['first', 'last'], true)) {
                return 'model';
            }

            if ($receiverShape === 'collection' && $methodName !== null
                && in_array(strtolower($methodName), ['getcollection', 'filter', 'map', 'values', 'unique', 'sort', 'reverse'], true)) {
                return 'collection';
            }

            return null;
        };
        $scopeNodes = [
            ...$finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class),
            ...$finder->findInstanceOf($ast, Node\Expr\Closure::class),
            ...$finder->findInstanceOf($ast, Node\Expr\ArrowFunction::class),
            ...$finder->findInstanceOf($ast, Node\Stmt\Function_::class),
        ];
        $helperShapes = [];

        for ($pass = 0; $pass < 3; $pass++) {
            foreach ($scopeNodes as $scope) {
                if (! $scope instanceof Node\Stmt\ClassMethod) {
                    continue;
                }
                $scopeClass = $classNameForScope($scope);

                if ($scopeClass === null) {
                    continue;
                }

                $variableContracts = [];

                foreach ($scope->params as $parameter) {
                    if (! $parameter->var instanceof Node\Expr\Variable || ! is_string($parameter->var->name)) {
                        continue;
                    }

                    foreach ($nativeTypeNames($parameter->type) as $typeName) {
                        foreach (array_keys($foreignMethods) as $contractName) {
                            if (strcasecmp($typeName, $contractName) === 0) {
                                $variableContracts[$parameter->var->name] = $contractName;
                            }
                        }
                    }
                }

                $variableShapes = [];
                foreach ($findInScope($scope, Node\Expr\Assign::class) as $assignment) {
                    if (! $assignment instanceof Node\Expr\Assign) {
                        continue;
                    }

                    $shape = $expressionShape($assignment->expr, $variableShapes, $propertyContracts, $variableContracts, $helperShapes, $scopeClass);

                    if ($shape === null) {
                        continue;
                    }

                    if ($assignment->var instanceof Node\Expr\Variable && is_string($assignment->var->name)) {
                        $variableShapes[$assignment->var->name] = $shape;
                    } elseif ($assignment->var instanceof Node\Expr\PropertyFetch
                        && $assignment->var->var instanceof Node\Expr\Variable
                        && $assignment->var->var->name === 'this'
                        && $assignment->var->name instanceof Node\Identifier) {
                        $propertyContracts[$assignment->var->name->toString()] = $shape;
                    }
                }

                foreach ($findInScope($scope, Node\Stmt\Return_::class) as $return) {
                    if (! $return instanceof Node\Stmt\Return_) {
                        continue;
                    }

                    $shape = $expressionShape($return->expr, $variableShapes, $propertyContracts, $variableContracts, $helperShapes, $scopeClass);

                    if ($shape !== null) {
                        $helperShapes[$scopeClass.'::'.$scope->name->toString()] = $shape;
                    }
                }
            }
        }

        $analyzeScope = static function (Node $scope) use (

            $nativeTypeNames,
            $foreignMethods,
            $propertyContracts,
            $helperShapes,
            $classForExpression,
            $expressionShape,
            $ormBuilderTerminals,
            $ormModelMutators,
            $ormCollectionMutators,
            $report,
            $relative,
            $classNameForScope,
            $findInScope,
        ): void {
            $scopeClass = $classNameForScope($scope);
            $variableContracts = [];

            if (property_exists($scope, 'params')) {
                foreach ($scope->params as $parameter) {
                    if (! $parameter->var instanceof Node\Expr\Variable || ! is_string($parameter->var->name)) {
                        continue;
                    }

                    foreach ($nativeTypeNames($parameter->type) as $typeName) {
                        foreach (array_keys($foreignMethods) as $contractName) {
                            if (strcasecmp($typeName, $contractName) === 0) {
                                $variableContracts[$parameter->var->name] = $contractName;
                            }
                        }
                    }
                }
            }

            foreach ($findInScope($scope, Node\Expr\Assign::class) as $assignment) {
                if (! $assignment instanceof Node\Expr\Assign) {
                    continue;
                }

                $contractName = $classForExpression($assignment->expr, $variableContracts, $propertyContracts);

                if ($contractName !== null && $assignment->var instanceof Node\Expr\Variable && is_string($assignment->var->name)) {
                    $variableContracts[$assignment->var->name] = $contractName;
                }
            }

            $variableShapes = [];
            $propertyShapes = $propertyContracts;

            foreach ($findInScope($scope, Node\Expr\Assign::class) as $assignment) {
                if (! $assignment instanceof Node\Expr\Assign) {
                    continue;
                }

                $shape = $expressionShape($assignment->expr, $variableShapes, $propertyShapes, $variableContracts, $helperShapes, $scopeClass);

                if ($shape === null) {
                    continue;
                }

                if ($assignment->var instanceof Node\Expr\Variable && is_string($assignment->var->name)) {
                    $variableShapes[$assignment->var->name] = $shape;
                } elseif ($assignment->var instanceof Node\Expr\PropertyFetch
                    && $assignment->var->var instanceof Node\Expr\Variable
                    && $assignment->var->var->name === 'this'
                    && $assignment->var->name instanceof Node\Identifier) {
                    $propertyShapes[$assignment->var->name->toString()] = $shape;
                }
            }

            $modelAssignmentNodes = [
                ...$findInScope($scope, Node\Expr\Assign::class),
                ...$findInScope($scope, Node\Expr\AssignOp::class),
            ];

            foreach ($modelAssignmentNodes as $assignment) {
                if (! $assignment instanceof Node\Expr\Assign && ! $assignment instanceof Node\Expr\AssignOp) {
                    continue;
                }

                $assignedValue = $assignment->var;
                $modelExpression = null;

                if ($assignedValue instanceof Node\Expr\PropertyFetch) {
                    $modelExpression = $assignedValue->var;
                } elseif ($assignedValue instanceof Node\Expr\ArrayDimFetch) {
                    $modelExpression = $assignedValue->var;
                }

                if ($modelExpression !== null
                    && $expressionShape($modelExpression, $variableShapes, $propertyShapes, $variableContracts, $helperShapes, $scopeClass) === 'model') {
                    $report('orm-mutation-escape', $relative, $assignment->getStartLine(), 'foreign-model', 'A model returned by another feature is being mutated here; move the operation to its owner.');
                }
            }

            foreach ($findInScope($scope, Node\Expr\MethodCall::class) as $call) {
                if (! $call instanceof Node\Expr\MethodCall) {
                    continue;
                }

                $methodName = $call->name instanceof Node\Identifier ? strtolower($call->name->toString()) : 'dynamic';
                $receiverShape = $expressionShape($call->var, $variableShapes, $propertyShapes, $variableContracts, $helperShapes, $scopeClass);
                if ($receiverShape === 'builder' && ! in_array($methodName, $ormBuilderTerminals, true)) {
                    $report('orm-query-escape', $relative, $call->getStartLine(), 'foreign-builder', 'A foreign owner builder is being composed here; consume it through a read terminal or pass typed criteria to the owner.');
                }

                if ($receiverShape === 'model' && in_array($methodName, array_map('strtolower', $ormModelMutators), true)) {
                    $report('orm-mutation-escape', $relative, $call->getStartLine(), 'foreign-model', 'A model returned by another feature is being mutated here; move the operation to its owner.');
                }

                if ($receiverShape === 'collection') {
                    $isMutation = in_array($methodName, array_map('strtolower', $ormCollectionMutators), true);
                    $callbackMutatesModel = false;

                    if (in_array($methodName, ['each', 'map', 'transform', 'tap'], true)) {
                        foreach ($call->args as $argument) {
                            if (! $argument->value instanceof Node\Expr\Closure && ! $argument->value instanceof Node\Expr\ArrowFunction) {
                                continue;
                            }

                            $callback = $argument->value;
                            $callbackVariables = [];

                            foreach ($callback->params as $parameter) {
                                if ($parameter->var instanceof Node\Expr\Variable && is_string($parameter->var->name)) {
                                    $callbackVariables[$parameter->var->name] = 'model';
                                }
                            }

                            foreach ($findInScope($callback, Node\Expr\Assign::class) as $callbackAssignment) {
                                if (! $callbackAssignment instanceof Node\Expr\Assign) {
                                    continue;
                                }

                                if ($callbackAssignment->var instanceof Node\Expr\Variable
                                    && is_string($callbackAssignment->var->name)
                                    && $callbackAssignment->expr instanceof Node\Expr\Variable
                                    && is_string($callbackAssignment->expr->name)
                                    && isset($callbackVariables[$callbackAssignment->expr->name])) {
                                    $callbackVariables[$callbackAssignment->var->name] = $callbackVariables[$callbackAssignment->expr->name];
                                }
                            }

                            foreach ($findInScope($callback, Node\Expr\MethodCall::class) as $callbackCall) {
                                if (! $callbackCall instanceof Node\Expr\MethodCall) {
                                    continue;
                                }

                                if ($callbackCall->var instanceof Node\Expr\Variable
                                    && is_string($callbackCall->var->name)
                                    && isset($callbackVariables[$callbackCall->var->name])
                                    && $callbackCall->name instanceof Node\Identifier
                                    && in_array(strtolower($callbackCall->name->toString()), array_map('strtolower', $ormModelMutators), true)) {
                                    $callbackMutatesModel = true;
                                }
                            }
                        }
                    }

                    if ($isMutation || $callbackMutatesModel) {
                        $report('orm-mutation-escape', $relative, $call->getStartLine(), 'foreign-model-collection', 'A collection returned by another feature is being mutated or used to mutate its models; move the operation to its owner.');
                    }
                }

            }
        };

        foreach ($scopeNodes as $scope) {
            $analyzeScope($scope);
        }

        $topLevelStatements = [];
        foreach ($ast as $rootNode) {
            if ($rootNode instanceof Node\Stmt\Namespace_) {
                foreach ($rootNode->stmts as $statement) {
                    if (! $statement instanceof Node\Stmt\ClassLike
                        && ! $statement instanceof Node\Stmt\Function_
                        && ! $statement instanceof Node\Stmt\Use_
                        && ! $statement instanceof Node\Stmt\GroupUse) {
                        $topLevelStatements[] = $statement;
                    }
                }
            } elseif (! $rootNode instanceof Node\Stmt\ClassLike
                && ! $rootNode instanceof Node\Stmt\Function_
                && ! $rootNode instanceof Node\Stmt\Use_
                && ! $rootNode instanceof Node\Stmt\GroupUse) {
                $topLevelStatements[] = $rootNode;
            }
        }

        if ($topLevelStatements !== []) {
            $topLevelScope = new Node\Stmt\Namespace_(null, $topLevelStatements);
            $parentTraverser = new NodeTraverser;
            $parentTraverser->addVisitor(new ParentConnectingVisitor);
            $topLevelScope = $parentTraverser->traverse([$topLevelScope])[0];
            $analyzeScope($topLevelScope);
        }
    };

    // Conventional Fortify contracts/pipeline are framework entrypoints, not
    // business Actions. All other actions belong in their owning features.
    $fortifyEntrypoints = [
        'app/Platform/Auth/Fortify/CreateNewUser.php',
        'app/Platform/Auth/Fortify/RecordSuccessfulLogin.php',
        'app/Platform/Auth/Fortify/ResetUserPassword.php',
    ];
    // An allowlisted framework hook must exist. A deleted adapter may not
    // leave behind a permanently ignored, stale architectural exception.
    foreach ($fortifyEntrypoints as $entrypoint) {
        if (! isset($paths[$entrypoint])) {
            $report('stale-fortify-entrypoint', $entrypoint, 1, $entrypoint, 'Remove the obsolete Fortify exception or restore its actual framework adapter.');
        }
    }

    // Root domain folders are retired. Only genuinely unowned framework
    // composition roots (Http, Providers, Platform, Shared, Features) and the
    // two framework user models may live outside a feature or the platform.
    $retiredRootDirectories = [
        'Services', 'Support', 'Data', 'Forms', 'Checkout', 'Listeners', 'Observers',
        'Policies', 'States', 'Contracts', 'Enums', 'Filament', 'Mcp', 'Livewire',
    ];
    // The explicit root-model allowlist lives in the checked-in configuration so
    // fixture projects can opt in to the gate with their own allowlist.
    $rootModelAllowlist = is_array($config['root_models'] ?? null) ? $config['root_models'] : null;
    foreach ($paths as $relative => $path) {
        if (str_starts_with($relative, 'app/Actions/') && ! in_array($relative, $fortifyEntrypoints, true)) {
            $report('root-domain-action', $relative, 1, $relative, 'Move this domain action into its owning feature, or place a genuine HTTP transport adapter under app/Http.');
        }
        foreach ($retiredRootDirectories as $retiredDirectory) {
            if (str_starts_with($relative, 'app/'.$retiredDirectory.'/')) {
                $report('root-domain', $relative, 1, $relative, 'app/'.$retiredDirectory.' is retired; move the class into its owning feature or app/Platform, without aliases or shims.');
            }
        }
        if ($rootModelAllowlist !== null
            && str_starts_with($relative, 'app/Models/')
            && ! in_array($relative, $rootModelAllowlist, true)) {
            $report('root-model', $relative, 1, $relative, 'Only app/Models/User.php and app/Models/PassportUser.php may stay at the root; move domain models into their owning feature.');
        }
    }

    // Check PHP, tests and Volt/Blade for references left behind after direct
    // cutovers. Unlike the feature import guard, these can be broken even when
    // no implementation file exists to inspect.
    $actionPattern = 'App\\\\Actions\\\\[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*';
    $actionImportRegex = '/^\\s*use\\s+('.$actionPattern.')(?:\\s+as\\s+\\w+)?\\s*;/m';
    $actionClassRegex = '/('.$actionPattern.')::class/';
    foreach (['app', 'resources', 'tests', 'config', 'bootstrap', 'routes', 'database', 'tools'] as $directory) {
        if (! is_dir($root.'/'.$directory)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            $content = file_get_contents($file->getPathname());
            if (! is_string($content)) {
                throw new RuntimeException('Could not read PHP file '.$relative);
            }

            foreach ([$actionImportRegex, $actionClassRegex] as $regex) {
                if (preg_match_all($regex, $content, $matches, PREG_OFFSET_CAPTURE) === false) {
                    throw new RuntimeException('Invalid root Action reference pattern.');
                }
                foreach ($matches[1] as [$className, $offset]) {
                    $classPath = $root.'/app/'.str_replace('\\', '/', substr($className, strlen('App\\'))).'.php';
                    if (is_file($classPath)) {
                        continue;
                    }

                    $line = substr_count(substr($content, 0, $offset), "\n") + 1;
                    $report('dangling-root-action', $relative, $line, $className, 'Deleted root Action is still imported or referenced; update the caller to the new owner without aliases.');
                }
            }

            if (in_array($directory, ['app', 'config', 'bootstrap', 'routes'], true)) {
                $retiredPrefixes = array_map(static fn (string $retired): string => 'App\\'.$retired.'\\', $retiredRootDirectories);
                $retiredModelClasses = array_map(
                    static fn (string $model): string => 'App\\Models\\'.$model,
                    ['Event', 'Institution', 'Person', 'Reference', 'Venue', 'Series', 'DonationChannel'],
                );

                foreach ([...$retiredPrefixes, ...$retiredModelClasses] as $retiredReference) {
                    $offset = 0;
                    while (($position = strpos($content, $retiredReference, $offset)) !== false) {
                        $line = substr_count(substr($content, 0, $position), "\n") + 1;
                        $report('root-namespace', $relative, $line, rtrim($retiredReference, '\\'), 'Retired root namespace is still referenced; import the owning feature or Platform class without aliases.');
                        $offset = $position + strlen($retiredReference);
                    }
                }
            }
        }
    }

    $effectiveSanctumTokenModel = 'Laravel\\Sanctum\\PersonalAccessToken';
    $sanctumExceptionRelations = [
        'app/Features/Identity/Support/HasUserRestoration.php' => 'tokens',
        'app/Features/Identity/Persistence/HasUserRestoration.php' => 'tokens',
        'app/Features/Identity/Persistence/EloquentAccountAccess.php' => 'tokens',
        'app/Features/Identity/Persistence/EloquentAccountSettingsStore.php' => 'tokens',
        'app/Platform/Auth/EloquentAccountAccess.php' => 'currentAccessToken',
        'app/Platform/Auth/EloquentAccountSettingsStore.php' => 'tokens',
        'app/Platform/Mcp/McpTokenManager.php' => 'tokens',
    ];
    $sanctumRegistrationCount = 0;
    $sanctumClassName = static function (Node\Name $name): string {
        $resolved = $name->getAttribute('resolvedName');

        return ltrim($resolved instanceof Node\Name ? $resolved->toString() : $name->toString(), '\\');
    };
    $literalClassName = static function (?Node $expression) use ($sanctumClassName): ?string {
        if ($expression instanceof Node\Expr\ClassConstFetch
            && $expression->class instanceof Node\Name
            && $expression->name instanceof Node\Identifier
            && strtolower($expression->name->toString()) === 'class') {
            return $sanctumClassName($expression->class);
        }

        if ($expression instanceof Node\Scalar\String_) {
            $className = ltrim(trim($expression->value), '\\');

            return preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/D', $className) === 1
                ? $className
                : null;
        }

        return null;
    };
    $recordSanctumRegistration = static function (?Node $expression, string $source) use (&$effectiveSanctumTokenModel, &$sanctumRegistrationCount, $literalClassName): void {
        $sanctumRegistrationCount++;

        if ($sanctumRegistrationCount > 1) {
            throw new RuntimeException("Multiple Sanctum personal access token model registrations found ({$source}).");
        }

        $modelClass = $literalClassName($expression);

        if ($modelClass === null) {
            throw new RuntimeException("Sanctum personal access token model registration must use one exact literal class ({$source}).");
        }

        $effectiveSanctumTokenModel = $modelClass;
    };

    foreach ($paths as $relative => $path) {
        $registrationAst = $parser->parse(file_get_contents($path)) ?? [];
        $registrationTraverser = new NodeTraverser;
        $registrationTraverser->addVisitor(new NameResolver);
        $registrationAst = $registrationTraverser->traverse($registrationAst);

        foreach ($finder->findInstanceOf($registrationAst, Node::class) as $node) {
            if ($node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier
                && strtolower($node->name->toString()) === 'usepersonalaccesstokenmodel') {
                if (! $node->class instanceof Node\Name) {
                    throw new RuntimeException("Dynamic Sanctum personal access token model registration in {$relative}.");
                }

                $resolvedClass = $sanctumClassName($node->class);
                $shortClass = substr($resolvedClass, strrpos($resolvedClass, '\\') === false ? 0 : strrpos($resolvedClass, '\\') + 1);

                if ($resolvedClass === 'Laravel\\Sanctum\\Sanctum') {
                    $recordSanctumRegistration($node->args[0]->value ?? null, $relative);
                } elseif (strcasecmp($shortClass, 'Sanctum') === 0) {
                    throw new RuntimeException("Unresolved Sanctum token model registration in {$relative}.");
                }
            }

            if (($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp)
                && $node->var instanceof Node\Expr\StaticPropertyFetch
                && $node->var->name instanceof Node\VarLikeIdentifier
                && strtolower($node->var->name->toString()) === 'personalaccesstokenmodel') {
                if (! $node->var->class instanceof Node\Name) {
                    throw new RuntimeException("Dynamic Sanctum token model property assignment in {$relative}.");
                }

                $resolvedClass = $sanctumClassName($node->var->class);
                $shortClass = substr($resolvedClass, strrpos($resolvedClass, '\\') === false ? 0 : strrpos($resolvedClass, '\\') + 1);

                if ($resolvedClass === 'Laravel\\Sanctum\\Sanctum') {
                    $recordSanctumRegistration($node->expr, $relative);
                } elseif (strcasecmp($shortClass, 'Sanctum') === 0) {
                    throw new RuntimeException("Unresolved Sanctum token model property assignment in {$relative}.");
                }
            }
        }
    }

    if ($effectiveSanctumTokenModel !== 'Laravel\\Sanctum\\PersonalAccessToken') {
        throw new RuntimeException('The application Sanctum token model must remain Laravel\\Sanctum\\PersonalAccessToken.');
    }

    $modelTables = [];
    foreach ($paths as $relative => $path) {
        $featureModel = preg_match('~^app/Features/[^/]+/Models/~', $relative) === 1;

        if (! $featureModel && ! preg_match('~^app/Models/~', $relative)) {
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
            $extends = $class->extends instanceof Node\Name ? $class->extends->toString() : '';
            if ($table !== null) {
                $modelTables[$declared] = 'table:'.$table;
            } elseif ($featureModel && ! str_ends_with($extends, 'Builder')) {
                $report('model-table', $relative, $class->getStartLine(), $declared, 'Declare an explicit literal $table on feature models so ownership survives moves and can be checked.');
            }
        }
    }
    foreach ($paths as $relative => $path) {
        $ast = $parser->parse(file_get_contents($path)) ?? [];
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $traverser->addVisitor(new ParentConnectingVisitor);
        $ast = $traverser->traverse($ast);
        $sourceFeature = preg_match('~^app/Features/([^/]+)/~', $relative, $match) ? $match[1] : null;
        $platform = str_starts_with($relative, 'app/Platform/');
        $shared = str_starts_with($relative, 'app/Shared/');
        // A feature's published contract is Features/X/Api/**. Transport
        // folders such as EntryPoints/Api/Admin are adapters, not public API.
        $public = $sourceFeature && preg_match('~^app/Features/[^/]+/Api/~', $relative) === 1
            && ! str_contains($relative, '/Api/Orm/');
        $models = $sourceFeature && str_contains($relative, '/Models/');
        // Framework composition roots bind concrete owners; they are not
        // reusable business modules and may name any canonical feature class.
        $compositionRoot = str_starts_with($relative, 'config/')
            || str_starts_with($relative, 'routes/')
            || str_starts_with($relative, 'bootstrap/')
            || str_starts_with($relative, 'app/Providers/')
            || str_starts_with($relative, 'app/Models/');
        $expectedClass = 'App\\'.str_replace('/', '\\', substr($relative, 4, -4));

        if ($public) {
            $contractSource = file_get_contents($path);

            if (is_string($contractSource)) {
                $modelLeakPatterns = [
                    '/App\\\\Features\\\\[A-Za-z]+\\\\Models\\\\/',
                    '/Illuminate\\\\Database\\\\Eloquent\\\\/',
                    '/Illuminate\\\\Contracts\\\\Pagination\\\\/',
                    '/Illuminate\\\\Support\\\\Collection/',
                    '/\bBuilder\b/',
                ];

                foreach ($modelLeakPatterns as $modelLeakPattern) {
                    if (preg_match($modelLeakPattern, $contractSource) === 1) {
                        $report('api-model-leak', $relative, 1, 'model/eloquent', 'Ordinary Api contracts must return scalar/card/read-model values; move ORM-shaped signatures to Api/Orm.');
                        break;
                    }
                }
            }

            foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $method) {
                $returnType = $method->returnType instanceof Node\NullableType ? $method->returnType->type : $method->returnType;
                $returnName = $returnType instanceof Node\Identifier
                    ? strtolower($returnType->toString())
                    : ($returnType instanceof Node\Name ? strtolower($returnType->toString()) : null);

                if ($returnName === 'object') {
                    $report('api-opaque-return', $relative, $method->getStartLine(), $returnName, 'Return a concrete immutable value or move model/builder-shaped methods to Api/Orm.');
                }

                foreach ($method->params as $parameter) {
                    $paramType = $parameter->type instanceof Node\NullableType ? $parameter->type->type : $parameter->type;
                    $paramName = $paramType instanceof Node\Identifier
                        ? strtolower($paramType->toString())
                        : ($paramType instanceof Node\Name ? strtolower($paramType->toString()) : null);

                    if ($paramName !== 'object') {
                        continue;
                    }

                    if ($parameter->var instanceof Node\Expr\Variable
                        && $parameter->var->name === 'request') {
                        continue;
                    }

                    $report('api-opaque-param', $relative, $parameter->getStartLine(), (string) $paramName, 'Pass scalar ids or immutable values; move model/builder-shaped parameters to Api/Orm.');
                }
            }
        }

        if ($sourceFeature && str_contains($relative, '/Api/Orm/')) {
            $ormSource = file_get_contents($path);

            if (is_string($ormSource)
                && (preg_match('/@internal\b/', $ormSource) !== 1 || preg_match('/Consumers:/', $ormSource) !== 1)) {
                $report('orm-port-contract', $relative, 1, 'Api/Orm', 'Every Api/Orm port must document @internal and its Consumers: list.');
            }

            foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $ormMethod) {
                if (! $ormMethod->isPublic()) {
                    continue;
                }
                $ormReturn = $ormMethod->returnType instanceof Node\NullableType ? $ormMethod->returnType->type : $ormMethod->returnType;
                $ormReturnName = $ormReturn instanceof Node\Identifier
                    ? strtolower($ormReturn->toString())
                    : ($ormReturn instanceof Node\Name ? strtolower($ormReturn->toString()) : null);

                if (in_array($ormReturnName, ['object', 'mixed'], true)) {
                    $report('orm-opaque-return', $relative, $ormMethod->getStartLine(), $ormMethod->name->toString(), 'Name the concrete owner model, Eloquent collection/builder or immutable value; opaque object/mixed is not an integration seam.');
                }
            }
        }

        if ($ormContracts !== []) {
            $analyzeOrmConsumer($relative, $ast, $sourceFeature, (string) file_get_contents($path));
        }

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

        // Model seams: a feature model may reuse another owner's relation trait
        // (a trait that only adds Eloquent relations). This is the sanctioned
        // cross-owner relationship seam; it is structural, not a waiver.
        $traitCache = [];
        $isForeignModelConcern = static function (string $target) use (&$traitCache, $paths): bool {
            if (array_key_exists($target, $traitCache)) {
                return $traitCache[$target];
            }

            $relative = 'app/'.str_replace('\\', '/', substr($target, strlen('App\\'))).'.php';
            $file = $paths[$relative] ?? null;

            if ($file === null || ! is_file($file)) {
                return $traitCache[$target] = false;
            }

            $source = file_get_contents($file);
            $short = substr($target, (int) strrpos($target, '\\') + 1);

            return $traitCache[$target] = is_string($source)
                && preg_match('/\btrait\s+'.preg_quote($short, '/').'\b/', $source) === 1;
        };

        $checkDependency = static function (string $target, int $line) use ($sourceFeature, $platform, $shared, $public, $compositionRoot, $relative, $featureOf, $report, $features, $owners, $modelTables, $isForeignModelConcern): void {
            $target = ltrim($target, '\\');
            $targetFeature = $featureOf($target);
            if ($targetFeature && ! isset($features[$targetFeature])) {
                $report('missing-feature', $relative, $line, $target, 'Referenced feature has no discovered manifest.');
            }
            if (($platform || $shared) && $targetFeature && ! str_starts_with($target, "App\\Features\\{$targetFeature}\\Api\\")) {
                $report('platform-direction', $relative, $line, $target, 'Platform/Shared must not depend on concrete features; use a generic contract.');
            } elseif (! $compositionRoot
                && ! ($sourceFeature && str_contains($target, '\\Models\\'))
                && ! ($sourceFeature && str_contains($relative, '/Models/') && $isForeignModelConcern($target))
                && $targetFeature && ($target !== "App\\Features\\{$targetFeature}\\FeatureServiceProvider" || ! in_array($relative, ['bootstrap/providers.php', 'config/app.php'], true)) && $targetFeature !== $sourceFeature && ! str_starts_with($target, "App\\Features\\{$targetFeature}\\Api\\")) {
                $report('feature-private', $relative, $line, $target, "Call {$targetFeature} through App\\Features\\{$targetFeature}\\Api; private types cannot cross its boundary.");
            }
            $authBridge = in_array($target, ['App\\Models\\User', 'App\\Models\\PassportUser'], true);

            if ($public && ! $authBridge && str_starts_with($target, 'App\\') && ! str_contains($target, '\\Api\\') && ! str_starts_with($target, 'App\\Shared\\')) {
                $report('api-leak', $relative, $line, $target, 'Public signatures and values may depend only on public Api or Shared contracts/values.');
            }
            if ($public && str_starts_with($target, 'Illuminate\\')) {
                $report('api-shape', $relative, $line, $target, 'Api contracts/values must not expose Laravel runtime types.');
            }
            $rootModelResource = $modelTables[$target] ?? null;
            $isOwnedRootModel = is_string($rootModelResource)
                && ($owners[$rootModelResource] ?? null) === $sourceFeature;

            $frameworkBridge = in_array($target, ['App\\Models\\User', 'App\\Models\\PassportUser', 'App\\Http\\Controllers\\Controller'], true);

            if ($sourceFeature && str_starts_with($target, 'App\\') && ! $targetFeature
                && ! str_starts_with($target, 'App\\Platform\\') && ! str_starts_with($target, 'App\\Shared\\')
                && ! $isOwnedRootModel && ! $frameworkBridge) {
                $report('feature-external', $relative, $line, $target, 'Move this dependency into its owning feature or expose an explicit Platform/Shared contract.');
            }
        };

        $foreignModelVars = [];
        if ($sourceFeature && ! $models) {
            foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $foreignMethod) {
                foreach ($foreignMethod->params as $foreignParam) {
                    if (! $foreignParam->type instanceof Node\Name || ! $foreignParam->var instanceof Node\Expr\Variable || ! is_string($foreignParam->var->name)) {
                        continue;
                    }
                    $foreignType = ltrim($foreignParam->type->toString(), '\\');
                    $foreignOwner = $featureOf($foreignType);
                    if ($foreignOwner !== null && $foreignOwner !== $sourceFeature && str_contains($foreignType, '\\Models\\')) {
                        $foreignModelVars[$foreignParam->var->name] = $foreignType;
                    }
                }
            }
            foreach ($finder->findInstanceOf($ast, Node\Stmt\Property::class) as $foreignProperty) {
                if (! $foreignProperty->type instanceof Node\Name) {
                    continue;
                }
                $foreignType = ltrim($foreignProperty->type->toString(), '\\');
                $foreignOwner = $featureOf($foreignType);
                if ($foreignOwner === null || $foreignOwner === $sourceFeature || ! str_contains($foreignType, '\\Models\\')) {
                    continue;
                }
                foreach ($foreignProperty->props as $foreignProp) {
                    $foreignModelVars['this->'.$foreignProp->name->toString()] = $foreignType;
                }
            }
        }
        $foreignMutationMethods = ['save', 'update', 'delete', 'forceDelete', 'restore', 'increment', 'decrement', 'push', 'create', 'insert', 'updateOrCreate', 'firstOrCreate', 'associate', 'dissociate', 'attach', 'detach', 'sync', 'syncWithoutDetaching', 'toggle', 'fill'];

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
            if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name) {
                $resolved = $node->class->getAttribute('resolvedName');
                $calledClass = ltrim($resolved instanceof Node\Name ? $resolved->toString() : $node->class->toString(), '\\');
                $calledFeature = $featureOf($calledClass);
                if ($sourceFeature && $calledFeature && $calledFeature !== $sourceFeature && str_contains($calledClass, '\\Models\\')) {
                    if (isset($readAdapterUsage[$relative])) {
                        $readAdapterUsage[$relative] = true;
                    } else {
                        $report('foreign-model-domain-operation', $relative, $node->getStartLine(), $calledClass, 'Cross-owner Eloquent static queries are not allowed; ask the owning feature Api for the read.');
                    }
                }
            }
            if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
                $resolved = $node->class->getAttribute('resolvedName');
                $constructed = ltrim($resolved instanceof Node\Name ? $resolved->toString() : $node->class->toString(), '\\');
                $constructedFeature = $featureOf($constructed);
                if ($sourceFeature && $constructedFeature && $constructedFeature !== $sourceFeature && str_contains($constructed, '\\Models\\')) {
                    if (isset($readAdapterUsage[$relative])) {
                        $readAdapterUsage[$relative] = true;
                    } else {
                        $report('foreign-model-domain-operation', $relative, $node->getStartLine(), $constructed, 'Cross-owner Eloquent models cannot be constructed directly; use the owning feature Action.');
                    }
                }
            }
            if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
                && in_array(strtolower($node->name->toString()), ['app', 'resolve'], true)
                && isset($node->args[0]) && $node->args[0] instanceof Node\Arg
                && $node->args[0]->value instanceof Node\Expr\ClassConstFetch
                && $node->args[0]->value->class instanceof Node\Name) {
                $resolved = $node->args[0]->value->class->getAttribute('resolvedName');
                $resolvedClass = ltrim($resolved instanceof Node\Name ? $resolved->toString() : $node->args[0]->value->class->toString(), '\\');
                $resolvedFeature = $featureOf($resolvedClass);
                if ($sourceFeature && $resolvedFeature && $resolvedFeature !== $sourceFeature && str_contains($resolvedClass, '\\Models\\')) {
                    $report('foreign-model-domain-operation', $relative, $node->getStartLine(), $resolvedClass, 'Resolve foreign Eloquent models through the owning feature Api, not the container.');
                }
            }
            if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier
                && in_array(strtolower($node->name->toString()), $foreignMutationMethods, true)) {
                $variableKey = null;
                if ($node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
                    $variableKey = $node->var->name;
                } elseif ($node->var instanceof Node\Expr\PropertyFetch
                    && $node->var->var instanceof Node\Expr\Variable && $node->var->var->name === 'this'
                    && $node->var->name instanceof Node\Identifier) {
                    $variableKey = 'this->'.$node->var->name->toString();
                }
                if ($variableKey !== null && isset($foreignModelVars[$variableKey])) {
                    $report('foreign-model-domain-operation', $relative, $node->getStartLine(), $foreignModelVars[$variableKey], 'Foreign Eloquent model mutations belong in the owning feature Action.');
                }
            }
            if ($models && $node instanceof Node\Stmt\PropertyProperty && $node->name->toString() === 'table'
                && $node->default instanceof Node\Scalar\String_ && ($owners['table:'.$node->default->value] ?? null) !== $sourceFeature) {
                $report('resource-owner', $relative, $node->getStartLine(), 'table:'.$node->default->value, 'Model table must be declared in this feature manifest.');
            }
        }
    }

    $viewsPath = $root.'/resources/views';

    if (is_dir($viewsPath)) {
        $bladeFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewsPath, FilesystemIterator::SKIP_DOTS));

        foreach ($bladeFiles as $bladeFile) {
            if (! $bladeFile->isFile() || ! str_ends_with($bladeFile->getFilename(), '.blade.php')) {
                continue;
            }

            $relative = substr($bladeFile->getPathname(), strlen($root) + 1);
            $bladeSource = file_get_contents($bladeFile->getPathname());

            if (! is_string($bladeSource)) {
                continue;
            }

            $bladeSource = preg_replace_callback(
                '~@php\b\s*(.*?)@endphp~s',
                static fn (array $match): string => "<?php\n".$match[1]."\n?>",
                $bladeSource,
            ) ?? $bladeSource;

            try {
                $bladeAst = $parser->parse($bladeSource) ?? [];
                $bladeTraverser = new NodeTraverser;
                $bladeTraverser->addVisitor(new NameResolver);
                $bladeTraverser->addVisitor(new ParentConnectingVisitor);
                $bladeAst = $bladeTraverser->traverse($bladeAst);
                $analyzeOrmConsumer($relative, $bladeAst, null, $bladeSource);
            } catch (Throwable $exception) {
                $report('orm-analysis-incomplete', $relative, 1, 'blade-parse', 'The ORM boundary checker could not parse this Blade/Volt consumer: '.$exception->getMessage());
            }
        }
    }

    $scannerSuppressible = ['feature-private', 'platform-direction', 'api-leak', 'api-shape'];
    $writeSuppressible = ['architecture.foreignWrite', 'architecture.writeLocation'];
    $suppressible = [...$scannerSuppressible, ...$writeSuppressible];
    $writeExceptionIsConsumed = static function (array $exception) use ($root, $owners, $modelTables, $catalog, $parser, $finder): bool {
        $relative = str_replace('\\', '/', $exception['file']);
        $path = $root.'/'.$relative;
        $resolvedRoot = realpath($root);
        $resolvedPath = realpath($path);

        if ($resolvedRoot === false || $resolvedPath === false || ! str_starts_with($resolvedPath, $resolvedRoot.DIRECTORY_SEPARATOR)) {
            return false;
        }

        $source = file_get_contents($resolvedPath);

        if ($source === false) {
            return false;
        }

        if (($exception['validated_by'] ?? null) === 'phpstan') {
            $verifiedTarget = $exception['target'];
            $verifiedModel = $exception['model'] ?? null;

            if (! str_starts_with($verifiedTarget, 'table:') || $verifiedTarget === 'table:unknown'
                || ! is_string($verifiedModel) || str_starts_with($verifiedModel, 'App\\Features\\')) {
                return false;
            }

            if ($verifiedModel !== 'Illuminate\\Database\\Query\\Builder') {
                $verifiedRelation = $exception['relation'] ?? null;
                $shortModel = substr($verifiedModel, (int) strrpos($verifiedModel, '\\') + 1);

                if (is_string($verifiedRelation) && $verifiedRelation !== '') {
                    if (! str_contains($source, $verifiedRelation)) {
                        return false;
                    }
                } elseif (! str_contains($source, $shortModel)) {
                    return false;
                }
            }

            return true;
        }

        $target = $exception['target'];
        $owner = $owners[$target] ?? null;
        $sourceFeature = preg_match('~^app/Features/([^/]+)/~', $relative, $match) === 1 ? $match[1] : null;
        $expectedRule = $owner !== null && $owner !== $sourceFeature
            ? 'architecture.foreignWrite'
            : 'architecture.writeLocation';
        $exactRootModelException = $owner === null
            && $sourceFeature === null
            && $exception['rule'] === 'architecture.writeLocation'
            && $target === 'table:unknown'
            && is_string($exception['model'] ?? null)
            && $exception['model'] !== '';

        if ($exception['rule'] !== $expectedRule || ($owner === null && $sourceFeature === null && ! $exactRootModelException)) {
            return false;
        }

        $ast = $parser->parse($source) ?? [];
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $traverser->addVisitor(new ParentConnectingVisitor);
        $ast = $traverser->traverse($ast);
        $relationOwner = $exception['relation_owner'] ?? null;
        $relationOwnerClasses = [];
        $relationOwnerTraits = [];

        if (is_string($relationOwner) && $relationOwner !== '') {
            foreach ($finder->findInstanceOf($ast, Node\Stmt\Class_::class) as $classNode) {
                $className = $classNode->namespacedName;

                if ($className instanceof Node\Name && ltrim($className->toString(), '\\') === $relationOwner) {
                    $relationOwnerClasses[$relationOwner] = true;

                    break;
                }
            }

            if ($relationOwnerClasses === [] && str_starts_with($relationOwner, 'App\\')) {
                $ownerPath = $root.'/app/'.str_replace('\\', '/', substr($relationOwner, strlen('App\\'))).'.php';

                if (is_file($ownerPath)) {
                    $ownerSource = file_get_contents($ownerPath);

                    if ($ownerSource !== false) {
                        $ownerAst = $parser->parse($ownerSource) ?? [];
                        $ownerTraverser = new NodeTraverser;
                        $ownerTraverser->addVisitor(new NameResolver);
                        $ownerAst = $ownerTraverser->traverse($ownerAst);
                        $traitNames = [];
                        $sourceNamespace = '';

                        foreach ($finder->findInstanceOf($ast, Node\Stmt\Namespace_::class) as $namespaceNode) {
                            $sourceNamespace = $namespaceNode->name?->toString() ?? '';
                        }

                        foreach ($finder->findInstanceOf($ast, Node\Stmt\Trait_::class) as $traitNode) {
                            $traitName = $traitNode->namespacedName;

                            if ($traitName instanceof Node\Name) {
                                $traitNames[] = ltrim($traitName->toString(), '\\');
                            } elseif ($traitNode->name !== null) {
                                $traitNames[] = ($sourceNamespace === '' ? '' : $sourceNamespace.'\\').$traitNode->name->toString();
                            }
                        }

                        $ownerNamespace = substr($relationOwner, 0, (int) strrpos($relationOwner, '\\'));
                        $ownerImports = [];

                        foreach ($finder->findInstanceOf($ownerAst, Node\Stmt\Use_::class) as $use) {
                            foreach ($use->uses as $useItem) {
                                $importName = ltrim($useItem->name->toString(), '\\');
                                $alias = $useItem->alias?->toString() ?? substr($importName, strrpos($importName, '\\') === false ? 0 : strrpos($importName, '\\') + 1);
                                $ownerImports[$alias] = $importName;
                            }
                        }

                        foreach ($finder->findInstanceOf($ownerAst, Node\Stmt\GroupUse::class) as $groupUse) {
                            $prefix = ltrim($groupUse->prefix->toString(), '\\');

                            foreach ($groupUse->uses as $useItem) {
                                $importName = $prefix.'\\'.$useItem->name->toString();
                                $alias = $useItem->alias?->toString() ?? substr($importName, strrpos($importName, '\\') + 1);
                                $ownerImports[$alias] = $importName;
                            }
                        }

                        foreach ($finder->findInstanceOf($ownerAst, Node\Stmt\TraitUse::class) as $traitUse) {
                            foreach ($traitUse->traits as $usedTrait) {
                                $resolvedTrait = $usedTrait->getAttribute('resolvedName');
                                $traitReference = ltrim($usedTrait->toString(), '\\');
                                $firstTraitSegment = explode('\\', $traitReference, 2)[0];
                                $usedTraitName = $resolvedTrait instanceof Node\Name
                                    ? ltrim($resolvedTrait->toString(), '\\')
                                    : ($usedTrait instanceof Node\Name\FullyQualified
                                        ? ltrim($usedTrait->toString(), '\\')
                                        : (isset($ownerImports[$firstTraitSegment])
                                            ? $ownerImports[$firstTraitSegment].substr($traitReference, strlen($firstTraitSegment))
                                            : $ownerNamespace.'\\'.$traitReference));

                                if (in_array($usedTraitName, $traitNames, true)) {
                                    $relationOwnerTraits[$usedTraitName] = true;
                                }
                            }
                        }
                    }
                }
            }
        }
        $mutators = [
            'save', 'savequietly', 'saveorfail', 'push', 'pushquietly', 'create', 'createquietly', 'forcecreate',
            'update', 'updatequietly', 'updateorcreate', 'firstorcreate', 'createorfirst', 'delete', 'deletequietly',
            'destroy', 'forcedestroy', 'forcedelete', 'restore', 'restoreorcreate', 'createorrestore', 'increment',
            'decrement', 'touch', 'insert', 'insertgetid', 'insertorignore', 'insertusing', 'upsert', 'truncate',
            'attach', 'detach', 'sync', 'syncwithoutdetaching', 'syncwithpivotvalues', 'toggle', 'updateexistingpivot',
            'savemany', 'createmany', 'statement', 'unprepared', 'affectingstatement', 'put', 'putfile', 'set',
            'setex', 'mset', 'hset', 'hmset', 'hdel', 'del', 'rpush', 'lpush', 'sadd', 'srem', 'zadd', 'zrem',
            'write', 'writestream', 'deletedirectory', 'makedirectory', 'copy', 'move', 'append', 'prepend',
            'forget', 'flush', 'forever', 'remember', 'rememberforever', 'add', 'pull',
        ];
        $isMutator = static function (Node $node) use ($mutators): bool {
            return ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall || $node instanceof Node\Expr\StaticCall)
                && $node->name instanceof Node\Identifier
                && in_array(strtolower($node->name->toString()), $mutators, true);
        };

        if ($target === 'store:unknown') {
            return false;
        }

        if (! str_starts_with($target, 'table:')) {
            return false;
        }

        $model = $exception['model'] ?? null;
        $relation = $exception['relation'] ?? null;
        $table = substr($target, strlen('table:'));
        $parentDeleteMutations = [];
        $findParentDeleteMutations = static function (array $nodes): array {
            $mutations = [];
            $visit = static function (Node $node) use (&$visit, &$mutations): void {
                if ($node instanceof Node\Stmt\ClassLike) {
                    return;
                }

                if ($node instanceof Node\Expr\StaticCall
                    && $node->class instanceof Node\Name
                    && strtolower($node->class->toString()) === 'parent'
                    && $node->name instanceof Node\Identifier
                    && strtolower($node->name->toString()) === 'delete') {
                    $mutations[] = $node;
                }

                foreach ($node->getSubNodeNames() as $subNodeName) {
                    $subNode = $node->{$subNodeName};
                    $children = is_array($subNode) ? $subNode : [$subNode];

                    foreach ($children as $child) {
                        if ($child instanceof Node) {
                            $visit($child);
                        }
                    }
                }
            };

            foreach ($nodes as $node) {
                if ($node instanceof Node) {
                    $visit($node);
                }
            }

            return $mutations;
        };

        if (is_string($model) && $model !== '') {
            foreach ($finder->findInstanceOf($ast, Node\Stmt\Class_::class) as $classNode) {
                $className = $classNode->namespacedName;

                if (! $className instanceof Node\Name || ltrim($className->toString(), '\\') !== $model) {
                    continue;
                }

                foreach ($classNode->getMethods() as $methodNode) {
                    if (strtolower($methodNode->name->toString()) !== 'delete') {
                        continue;
                    }

                    foreach ($findParentDeleteMutations($methodNode->stmts ?? []) as $parentDeleteMutation) {
                        $parentDeleteMutations[spl_object_id($parentDeleteMutation)] = true;
                    }
                }
            }
        }

        $aliasResources = [];

        foreach ($catalog['aliases'] as $featureAliases) {
            foreach ($featureAliases as $alias => $resource) {
                $aliasResources[$alias] = $resource;
            }
        }

        $resourceModels = [];

        foreach ($modelTables as $modelClass => $resource) {
            $resourceModels[$resource][] = $modelClass;
        }
        $functionLike = static fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod
            || $node instanceof Node\Stmt\Function_
            || $node instanceof Node\Expr\Closure
            || $node instanceof Node\Expr\ArrowFunction;
        $relationOwnerScopes = [];
        $nodeClassNames = [];
        $mapRelationOwnerScopes = static function (Node $node, ?string $className = null) use (&$mapRelationOwnerScopes, &$relationOwnerScopes, &$nodeClassNames, $functionLike, $relationOwner, $relationOwnerTraits): void {
            if ($node instanceof Node\Stmt\ClassLike) {
                $declaredName = $node->namespacedName;
                $className = $declaredName instanceof Node\Name ? ltrim($declaredName->toString(), '\\') : null;
            }

            $nodeClassNames[spl_object_id($node)] = $className;

            if ($functionLike($node) && (is_string($relationOwner) && $className === $relationOwner
                || $className !== null && isset($relationOwnerTraits[$className]))) {
                $relationOwnerScopes[spl_object_id($node)] = true;
            }

            foreach ($node->getSubNodeNames() as $subNodeName) {
                $subNode = $node->{$subNodeName};
                $children = is_array($subNode) ? $subNode : [$subNode];

                foreach ($children as $child) {
                    if ($child instanceof Node) {
                        $mapRelationOwnerScopes($child, $className);
                    }
                }
            }
        };

        foreach ($ast as $rootNode) {
            $mapRelationOwnerScopes($rootNode);
        }

        $collectNodes = static function (Node $node) use (&$collectNodes, $functionLike): array {
            $nodes = [$node];

            foreach ($node->getSubNodeNames() as $subNodeName) {
                $subNode = $node->{$subNodeName};
                $children = is_array($subNode) ? $subNode : [$subNode];

                foreach ($children as $child) {
                    if (! $child instanceof Node || $functionLike($child)) {
                        continue;
                    }

                    array_push($nodes, ...$collectNodes($child));
                }
            }

            return $nodes;
        };
        $resolveName = static function (Node\Name $name): string {
            $resolved = $name->getAttribute('resolvedName');

            return ltrim($resolved instanceof Node\Name ? $resolved->toString() : $name->toString(), '\\');
        };
        $typeMatchesClass = static function (?Node $type, string $expectedClass) use (&$typeMatchesClass, $resolveName): bool {
            if ($type === null) {
                return false;
            }

            if ($type instanceof Node\Name) {
                return $resolveName($type) === $expectedClass;
            }

            if ($type instanceof Node\NullableType) {
                return $typeMatchesClass($type->type, $expectedClass);
            }

            if ($type instanceof Node\UnionType) {
                foreach ($type->types as $innerType) {
                    if ($innerType instanceof Node\Name && strtolower($resolveName($innerType)) === 'null') {
                        continue;
                    }

                    if (! $typeMatchesClass($innerType, $expectedClass)) {
                        return false;
                    }
                }

                return $type->types !== [];
            }

            if ($type instanceof Node\IntersectionType) {
                foreach ($type->types as $innerType) {
                    if ($typeMatchesClass($innerType, $expectedClass)) {
                        return true;
                    }
                }
            }

            return false;
        };
        $typeGuaranteesClass = static fn (?Node $type, string $expectedClass): bool => $typeMatchesClass($type, $expectedClass);
        $typeMatchesModel = static fn (?Node $type): bool => is_string($model) && $model !== '' && $typeMatchesClass($type, $model);
        $typeMatchesRelationOwner = static fn (?Node $type): bool => is_string($relationOwner) && $relationOwner !== '' && $typeMatchesClass($type, $relationOwner);
        $propertyModels = [];
        $propertyOwners = [];
        $propertyClasses = [];

        $typeNames = static function (?Node $type) use (&$typeNames, $resolveName): array {
            if ($type instanceof Node\Name) {
                return [$resolveName($type)];
            }

            if ($type instanceof Node\NullableType) {
                return $typeNames($type->type);
            }

            if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
                $names = [];

                foreach ($type->types as $innerType) {
                    array_push($names, ...$typeNames($innerType));
                }

                return $names;
            }

            return [];
        };
        $typeClassArms = static function (?Node $type) use (&$typeClassArms, $typeNames): array {
            if ($type === null) {
                return [];
            }

            if ($type instanceof Node\NullableType) {
                return [...$typeClassArms($type->type), ['null']];
            }

            if ($type instanceof Node\UnionType) {
                $arms = [];

                foreach ($type->types as $innerType) {
                    array_push($arms, ...$typeClassArms($innerType));
                }

                return $arms;
            }

            return [$typeNames($type)];
        };
        $propertyClassArms = [];

        foreach ($finder->findInstanceOf($ast, Node\Stmt\Class_::class) as $classNode) {
            $declaringClass = $nodeClassNames[spl_object_id($classNode)] ?? null;

            if (! is_string($declaringClass) || $declaringClass === '') {
                continue;
            }

            foreach ($classNode->getProperties() as $property) {
                foreach ($property->props as $propertyNode) {
                    $propertyName = $propertyNode->name->toString();

                    if ($typeMatchesModel($property->type)) {
                        $propertyModels[$declaringClass][$propertyName] = true;
                    }
                    if ($typeMatchesRelationOwner($property->type)) {
                        $propertyOwners[$declaringClass][$propertyName] = true;
                    }

                    $propertyClasses[$declaringClass][$propertyName] = $typeNames($property->type);
                    $propertyClassArms[$declaringClass][$propertyName] = $typeClassArms($property->type);
                }
            }

            foreach ($classNode->getMethods() as $methodNode) {
                if ($methodNode->name->toString() !== '__construct') {
                    continue;
                }

                foreach ($methodNode->params as $parameter) {
                    if ($parameter->flags === 0 || ! $parameter->var instanceof Node\Expr\Variable || ! is_string($parameter->var->name)) {
                        continue;
                    }

                    $propertyName = $parameter->var->name;

                    if ($typeMatchesModel($parameter->type)) {
                        $propertyModels[$declaringClass][$propertyName] = true;
                    }
                    if ($typeMatchesRelationOwner($parameter->type)) {
                        $propertyOwners[$declaringClass][$propertyName] = true;
                    }

                    $propertyClasses[$declaringClass][$propertyName] = $typeNames($parameter->type);
                    $propertyClassArms[$declaringClass][$propertyName] = $typeClassArms($parameter->type);
                }
            }
        }

        $externalMethodModels = [];
        $externalMethodOwners = [];

        foreach ($propertyClasses as $classProperties) {
            foreach ($classProperties as $classNames) {
                foreach ($classNames as $className) {
                    if (! str_starts_with($className, 'App\\')) {
                        continue;
                    }

                    $classPath = $root.'/app/'.str_replace('\\', '/', substr($className, strlen('App\\'))).'.php';

                    if (! is_file($classPath)) {
                        continue;
                    }

                    $classSource = file_get_contents($classPath);

                    if ($classSource === false) {
                        continue;
                    }

                    $classAst = $parser->parse($classSource) ?? [];
                    $classTraverser = new NodeTraverser;
                    $classTraverser->addVisitor(new NameResolver);
                    $classAst = $classTraverser->traverse($classAst);

                    foreach ($finder->findInstanceOf($classAst, Node\Stmt\ClassMethod::class) as $classMethod) {
                        if ($typeMatchesModel($classMethod->returnType)) {
                            $externalMethodModels[$className][$classMethod->name->toString()] = true;
                        }
                        if ($typeMatchesRelationOwner($classMethod->returnType)) {
                            $externalMethodOwners[$className][$classMethod->name->toString()] = true;
                        }
                    }
                }
            }
        }
        $propertyMethodReturnsClass = static function (string $declaringClass, string $propertyName, string $methodName, array $methodClasses) use ($propertyClassArms): bool {
            $arms = $propertyClassArms[$declaringClass][$propertyName] ?? [];
            $hasNonNullClassArm = false;

            if ($arms === []) {
                return false;
            }

            foreach ($arms as $armClasses) {
                if ($armClasses === []) {
                    return false;
                }

                $armHasNonNullClass = false;
                $armGuaranteesMethodReturn = false;

                foreach ($armClasses as $className) {
                    if ($className === 'null') {
                        continue;
                    }

                    $armHasNonNullClass = true;

                    if (isset($methodClasses[$className][$methodName])) {
                        $armGuaranteesMethodReturn = true;
                    }
                }

                if (! $armHasNonNullClass) {
                    continue;
                }

                if (! $armGuaranteesMethodReturn) {
                    return false;
                }

                $hasNonNullClassArm = true;
            }

            return $hasNonNullClassArm;
        };

        $imports = [];

        foreach ($finder->findInstanceOf($ast, Node\Stmt\Use_::class) as $use) {
            foreach ($use->uses as $useItem) {
                $useName = $useItem->name->toString();
                $alias = $useItem->alias?->toString() ?? substr($useName, strrpos($useName, '\\') === false ? 0 : strrpos($useName, '\\') + 1);
                $imports[$alias] = ltrim($useItem->name->toString(), '\\');
            }
        }

        foreach ($finder->findInstanceOf($ast, Node\Stmt\GroupUse::class) as $groupUse) {
            foreach ($groupUse->uses as $useItem) {
                $useName = $useItem->name->toString();
                $alias = $useItem->alias?->toString() ?? substr($useName, strrpos($useName, '\\') === false ? 0 : strrpos($useName, '\\') + 1);
                $imports[$alias] = ltrim($groupUse->prefix->toString().'\\'.$useItem->name->toString(), '\\');
            }
        }

        $importsByNamespace = [];
        $globalImports = [];
        $collectNamespaceImports = static function (array $statements): array {
            $namespaceImports = [];

            foreach ($statements as $statement) {
                if ($statement instanceof Node\Stmt\Use_) {
                    foreach ($statement->uses as $useItem) {
                        $importName = ltrim($useItem->name->toString(), '\\');
                        $alias = $useItem->alias?->toString() ?? substr($importName, strrpos($importName, '\\') === false ? 0 : strrpos($importName, '\\') + 1);
                        $namespaceImports[$alias] = $importName;
                    }
                }

                if ($statement instanceof Node\Stmt\GroupUse) {
                    $prefix = ltrim($statement->prefix->toString(), '\\');

                    foreach ($statement->uses as $useItem) {
                        $importName = $prefix.'\\'.$useItem->name->toString();
                        $alias = $useItem->alias?->toString() ?? substr($importName, strrpos($importName, '\\') === false ? 0 : strrpos($importName, '\\') + 1);
                        $namespaceImports[$alias] = $importName;
                    }
                }
            }

            return $namespaceImports;
        };

        foreach ($ast as $rootNode) {
            if ($rootNode instanceof Node\Stmt\Namespace_) {
                $namespaceName = $rootNode->name?->toString() ?? '';
                $importsByNamespace[$namespaceName] = $collectNamespaceImports($rootNode->stmts);

                continue;
            }

            if ($rootNode instanceof Node\Stmt\Use_ || $rootNode instanceof Node\Stmt\GroupUse) {
                $globalImports = $collectNamespaceImports([$rootNode]);
            }
        }

        $importsForClass = static function (?string $className) use ($globalImports, $importsByNamespace): array {
            if (! is_string($className) || $className === '' || ! str_contains($className, '\\')) {
                return $globalImports;
            }

            $namespaceName = substr($className, 0, strrpos($className, '\\'));

            return $importsByNamespace[$namespaceName] ?? $globalImports;
        };

        $phpDocParserConfig = new ParserConfig([]);
        $phpDocConstExprParser = new ConstExprParser($phpDocParserConfig);
        $phpDocParser = new PhpDocParser(
            $phpDocParserConfig,
            new TypeParser($phpDocParserConfig, $phpDocConstExprParser),
            $phpDocConstExprParser,
        );
        $phpDocLexer = new PhpDocLexer($phpDocParserConfig);
        $phpDocTypeMatchesClass = static function (PhpDocTypeNode $type, string $expectedClass, array $imports, string $namespaceName) use (&$phpDocTypeMatchesClass): bool {
            if ($type instanceof NullableTypeNode) {
                return $phpDocTypeMatchesClass($type->type, $expectedClass, $imports, $namespaceName);
            }

            if ($type instanceof UnionTypeNode) {
                foreach ($type->types as $innerType) {
                    if ($innerType instanceof IdentifierTypeNode && strtolower($innerType->name) === 'null') {
                        continue;
                    }

                    if (! $phpDocTypeMatchesClass($innerType, $expectedClass, $imports, $namespaceName)) {
                        return false;
                    }
                }

                return $type->types !== [];
            }

            if ($type instanceof IntersectionTypeNode) {
                foreach ($type->types as $innerType) {
                    if ($phpDocTypeMatchesClass($innerType, $expectedClass, $imports, $namespaceName)) {
                        return true;
                    }
                }

                return false;
            }

            $name = $type instanceof GenericTypeNode ? $type->type : $type;

            if (! $name instanceof IdentifierTypeNode) {
                return false;
            }

            $typeName = $name->name;

            if (str_starts_with($typeName, '\\')) {
                $resolvedName = ltrim($typeName, '\\');
            } else {
                $segments = explode('\\', $typeName, 2);
                $firstSegment = $segments[0];
                $resolvedName = isset($imports[$firstSegment])
                    ? $imports[$firstSegment].(isset($segments[1]) ? '\\'.$segments[1] : '')
                    : ($namespaceName === '' ? $typeName : $namespaceName.'\\'.$typeName);
            }

            return $resolvedName === $expectedClass;
        };

        $phpDocIdentifierMatchesClass = static function (PhpDocTypeNode $type, string $expectedClass, array $imports, string $namespaceName): bool {
            if (! $type instanceof IdentifierTypeNode) {
                return false;
            }

            $typeName = $type->name;

            if (str_starts_with($typeName, '\\')) {
                $resolvedName = ltrim($typeName, '\\');
            } else {
                $segments = explode('\\', $typeName, 2);
                $firstSegment = $segments[0];
                $resolvedName = isset($imports[$firstSegment])
                    ? $imports[$firstSegment].(isset($segments[1]) ? '\\'.$segments[1] : '')
                    : ($namespaceName === '' ? $typeName : $namespaceName.'\\'.$typeName);
            }

            return $resolvedName === $expectedClass;
        };
        $phpDocBuilderTypeMatchesModel = static function (PhpDocTypeNode $type, string $expectedModel, array $imports, string $namespaceName) use ($phpDocIdentifierMatchesClass): bool {
            return $type instanceof GenericTypeNode
                && count($type->genericTypes) === 1
                && $phpDocIdentifierMatchesClass($type->type, 'Illuminate\\Database\\Eloquent\\Builder', $imports, $namespaceName)
                && $phpDocIdentifierMatchesClass($type->genericTypes[0], $expectedModel, $imports, $namespaceName);
        };
        $methodReturnsClass = static function (Node\Stmt\ClassMethod $methodNode, string $expectedClass, bool $allowModelBuilderGeneric) use ($model, $typeGuaranteesClass, $importsForClass, $nodeClassNames, $phpDocParser, $phpDocLexer, $phpDocTypeMatchesClass, $phpDocBuilderTypeMatchesModel): bool {
            if ($typeGuaranteesClass($methodNode->returnType, $expectedClass)) {
                return true;
            }

            $docComment = $methodNode->getDocComment()?->getText() ?? '';

            if ($docComment === '') {
                return false;
            }

            try {
                $phpDoc = $phpDocParser->parse(new TokenIterator($phpDocLexer->tokenize($docComment)));
            } catch (Throwable) {
                return false;
            }

            $declaringClass = $nodeClassNames[spl_object_id($methodNode)] ?? null;
            $imports = $importsForClass(is_string($declaringClass) ? $declaringClass : null);
            $namespaceName = is_string($declaringClass) && str_contains($declaringClass, '\\')
                ? substr($declaringClass, 0, strrpos($declaringClass, '\\'))
                : '';

            foreach ($phpDoc->children as $child) {
                if ($child instanceof PhpDocTagNode && $child->name === '@return'
                    && $child->value instanceof ReturnTagValueNode
                    && ($phpDocTypeMatchesClass($child->value->type, $expectedClass, $imports, $namespaceName)
                        || ($allowModelBuilderGeneric && is_string($model) && $expectedClass === $model
                            && $phpDocBuilderTypeMatchesModel($child->value->type, $model, $imports, $namespaceName)))) {
                    return true;
                }
            }

            return false;
        };
        $methodParamMatchesClass = static function (Node $methodNode, string $parameterName, string $expectedClass) use ($nodeClassNames, $importsForClass, $phpDocParser, $phpDocLexer, $phpDocTypeMatchesClass): bool {
            $docComment = $methodNode->getDocComment()?->getText() ?? '';

            if ($docComment === '') {
                return false;
            }

            try {
                $phpDoc = $phpDocParser->parse(new TokenIterator($phpDocLexer->tokenize($docComment)));
            } catch (Throwable) {
                return false;
            }

            $declaringClass = $nodeClassNames[spl_object_id($methodNode)] ?? null;
            $imports = $importsForClass(is_string($declaringClass) ? $declaringClass : null);
            $namespaceName = is_string($declaringClass) && str_contains($declaringClass, '\\')
                ? substr($declaringClass, 0, strrpos($declaringClass, '\\'))
                : '';

            foreach ($phpDoc->children as $child) {
                if ($child instanceof PhpDocTagNode && $child->name === '@param'
                    && $child->value instanceof ParamTagValueNode
                    && ltrim($child->value->parameterName, '$') === $parameterName
                    && $phpDocTypeMatchesClass($child->value->type, $expectedClass, $imports, $namespaceName)) {
                    return true;
                }
            }

            return false;
        };
        $methodModels = [];
        $methodOwners = [];

        foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $methodNode) {
            $declaringClass = $nodeClassNames[spl_object_id($methodNode)] ?? null;

            if (! is_string($declaringClass) || $declaringClass === '') {
                continue;
            }

            $methodName = $methodNode->name->toString();

            if (is_string($model) && $model !== '' && $methodReturnsClass($methodNode, $model, true)) {
                $methodModels[$declaringClass][$methodName] = true;
            }
            if (is_string($relationOwner) && $relationOwner !== '' && $methodReturnsClass($methodNode, $relationOwner, false)) {
                $methodOwners[$declaringClass][$methodName] = true;
            }
        }

        $expressionEvidence = static function (Node $expression, array $bindings) use (&$expressionEvidence, &$nodeClassNames, $model, $relation, $relationOwner, $table, $propertyModels, $propertyOwners, $propertyMethodReturnsClass, $methodModels, $methodOwners, $externalMethodModels, $externalMethodOwners, $resolveName, $aliasResources, $resourceModels): array {
            $evidence = ['model' => false, 'relation' => false, 'table' => false, 'owner' => false];
            $declaringClass = $nodeClassNames[spl_object_id($expression)] ?? null;
            $merge = static function (array &$targetEvidence, array $sourceEvidence): void {
                foreach ($targetEvidence as $key => $value) {
                    $targetEvidence[$key] = $value || ($sourceEvidence[$key] ?? false);
                }
            };

            if ($expression instanceof Node\Expr\Variable && is_string($expression->name)) {
                return $bindings[$expression->name] ?? $evidence;
            }

            if ($expression instanceof Node\Expr\PropertyFetch) {
                if ($expression->var instanceof Node\Expr\Variable && $expression->var->name === 'this'
                    && $expression->name instanceof Node\Identifier && is_string($declaringClass)
                    && isset($propertyModels[$declaringClass][$expression->name->toString()])) {
                    $evidence['model'] = true;
                }

                if ($expression->var instanceof Node\Expr\Variable && $expression->var->name === 'this'
                    && $expression->name instanceof Node\Identifier && is_string($relationOwner) && is_string($declaringClass)
                    && isset($propertyOwners[$declaringClass][$expression->name->toString()])) {
                    $evidence['owner'] = true;
                }

                $merge($evidence, $expressionEvidence($expression->var, $bindings));

                return $evidence;
            }

            if ($expression instanceof Node\Expr\MethodCall || $expression instanceof Node\Expr\NullsafeMethodCall) {
                $methodName = $expression->name instanceof Node\Identifier ? $expression->name->toString() : null;
                $receiverEvidence = $expressionEvidence($expression->var, $bindings);

                if ($methodName !== null && is_string($relation) && $methodName === $relation && $receiverEvidence['owner']) {
                    $evidence['relation'] = true;
                }
                if ($methodName !== null && is_string($declaringClass)
                    && isset($methodModels[$declaringClass][$methodName])
                    && $expression->var instanceof Node\Expr\Variable && $expression->var->name === 'this') {
                    $evidence['model'] = true;
                }
                if ($methodName !== null && is_string($declaringClass)
                    && isset($methodOwners[$declaringClass][$methodName])
                    && $expression->var instanceof Node\Expr\Variable && $expression->var->name === 'this') {
                    $evidence['owner'] = true;
                }
                if ($methodName !== null && is_string($declaringClass) && $expression->var instanceof Node\Expr\PropertyFetch
                    && $expression->var->var instanceof Node\Expr\Variable && $expression->var->var->name === 'this'
                    && $expression->var->name instanceof Node\Identifier) {
                    $propertyName = $expression->var->name->toString();

                    if ($propertyMethodReturnsClass($declaringClass, $propertyName, $methodName, $externalMethodModels)) {
                        $evidence['model'] = true;
                    }

                    if ($propertyMethodReturnsClass($declaringClass, $propertyName, $methodName, $externalMethodOwners)) {
                        $evidence['owner'] = true;
                    }
                }
                if ($methodName !== null && in_array(strtolower($methodName), ['table', 'from'], true)
                    && isset($expression->args[0]) && $expression->args[0]->value instanceof Node\Scalar\String_
                    && $expression->args[0]->value->value === $table) {
                    $evidence['table'] = true;
                }

                if ($methodName === 'query' && isset($expression->args[0])
                    && $expression->args[0]->value instanceof Node\Scalar\String_) {
                    $resource = $aliasResources[$expression->args[0]->value->value] ?? null;
                    $modelsForResource = is_string($resource) ? $resourceModels[$resource] ?? [] : [];

                    if (is_string($relationOwner) && $modelsForResource === [$relationOwner]) {
                        $evidence['owner'] = true;
                    }
                }

                $merge($evidence, $receiverEvidence);

                return $evidence;
            }

            if ($expression instanceof Node\Expr\StaticCall) {
                $className = $expression->class instanceof Node\Name ? $resolveName($expression->class) : null;

                if ($expression->class instanceof Node\Name && is_string($model) && $resolveName($expression->class) === $model) {
                    $evidence['model'] = true;
                }
                if ($expression->class instanceof Node\Name && is_string($relationOwner) && $className === $relationOwner) {
                    $evidence['owner'] = true;
                }
                if ($expression->name instanceof Node\Identifier && is_string($relation) && $expression->name->toString() === $relation
                    && $className === $relationOwner) {
                    $evidence['relation'] = true;
                }
                if ($expression->name instanceof Node\Identifier && in_array(strtolower($expression->name->toString()), ['table', 'from'], true)
                    && isset($expression->args[0]) && $expression->args[0]->value instanceof Node\Scalar\String_
                    && $expression->args[0]->value->value === $table) {
                    $evidence['table'] = true;
                }
                if ($expression->name instanceof Node\Identifier && strtolower($expression->name->toString()) === 'query'
                    && isset($expression->args[0]) && $expression->args[0]->value instanceof Node\Scalar\String_) {
                    $resource = $aliasResources[$expression->args[0]->value->value] ?? null;
                    $modelsForResource = is_string($resource) ? $resourceModels[$resource] ?? [] : [];

                    if (is_string($relationOwner) && $modelsForResource === [$relationOwner]) {
                        $evidence['owner'] = true;
                    }
                }

                return $evidence;
            }

            if ($expression instanceof Node\Name && is_string($model) && $resolveName($expression) === $model) {
                $evidence['model'] = true;

                return $evidence;
            }
            if ($expression instanceof Node\Name && is_string($relationOwner) && $resolveName($expression) === $relationOwner) {
                $evidence['owner'] = true;

                return $evidence;
            }

            if ($expression instanceof Node\Expr\New_ && $expression->class instanceof Node\Name
                && is_string($model) && $resolveName($expression->class) === $model) {
                $evidence['model'] = true;

                return $evidence;
            }
            if ($expression instanceof Node\Expr\New_ && $expression->class instanceof Node\Name
                && is_string($relationOwner) && $resolveName($expression->class) === $relationOwner) {
                $evidence['owner'] = true;

                return $evidence;
            }

            if ($expression instanceof Node\Expr\Ternary) {
                $arms = $expression->if instanceof Node\Expr
                    ? [$expression->if, $expression->else]
                    : [$expression->cond, $expression->else];
                $armEvidence = array_map(
                    static fn (Node\Expr $arm): array => $expressionEvidence($arm, $bindings),
                    $arms,
                );

                foreach (array_keys($evidence) as $key) {
                    $evidence[$key] = count(array_filter(
                        $armEvidence,
                        static fn (array $arm): bool => $arm[$key] ?? false,
                    )) === count($armEvidence);
                }

                return $evidence;
            }

            return $evidence;
        };
        $callbackRelationBindings = [];
        $findNestedFunctionScopes = static function (Node $node) use (&$findNestedFunctionScopes, $functionLike): array {
            $scopes = [];

            foreach ($node->getSubNodeNames() as $subNodeName) {
                $subNode = $node->{$subNodeName};
                $children = is_array($subNode) ? $subNode : [$subNode];

                foreach ($children as $child) {
                    if (! $child instanceof Node) {
                        continue;
                    }

                    if ($functionLike($child)) {
                        $scopes[] = $child;

                        continue;
                    }

                    array_push($scopes, ...$findNestedFunctionScopes($child));
                }
            }

            return $scopes;
        };
        $branchPathForNode = static function (Node $node): array {
            $branchTypes = [
                Node\Stmt\If_::class,
                Node\Stmt\ElseIf_::class,
                Node\Stmt\Else_::class,
                Node\Stmt\For_::class,
                Node\Stmt\Foreach_::class,
                Node\Stmt\While_::class,
                Node\Stmt\Do_::class,
                Node\Stmt\Switch_::class,
                Node\Stmt\Case_::class,
                Node\Stmt\TryCatch::class,
                Node\Stmt\Catch_::class,
                Node\Stmt\Finally_::class,
            ];
            $branchPath = [];
            $current = $node;

            while (($parent = $current->getAttribute('parent')) instanceof Node) {
                if (in_array($parent::class, $branchTypes, true)) {
                    $branch = 'unknown';

                    foreach ($parent->getSubNodeNames() as $subNodeName) {
                        $parentSubNode = $parent->{$subNodeName};

                        if ($parentSubNode === $current) {
                            $branch = $subNodeName;

                            break;
                        }

                        if (is_array($parentSubNode)) {
                            foreach ($parentSubNode as $child) {
                                if ($child === $current) {
                                    $branch = $subNodeName;

                                    if (in_array($subNodeName, ['elseifs', 'cases', 'catches'], true)) {
                                        $branch .= ':arm:'.spl_object_id($current);
                                    }

                                    break 2;
                                }
                            }
                        }
                    }

                    $branchPath[] = spl_object_id($parent).':'.$branch;
                }

                $current = $parent;
            }

            return array_reverse($branchPath);
        };
        $branchPathIsPrefix = static function (array $prefix, array $path): bool {
            if (count($prefix) > count($path)) {
                return false;
            }

            foreach ($prefix as $index => $branch) {
                if (($path[$index] ?? null) !== $branch) {
                    return false;
                }
            }

            return true;
        };
        $findControlsAtArm = static function (Node $node, array $branchPath, string $controlClass, string $arm): array {
            $controls = [];
            $current = $node;

            while (($parent = $current->getAttribute('parent')) instanceof Node) {
                if ($parent instanceof $controlClass && in_array(spl_object_id($parent).':'.$arm, $branchPath, true)) {
                    $controls[] = $parent;
                }

                $current = $parent;
            }

            return $controls;
        };
        $loopAssignmentCanReachPosition = static function (Node $assignment, Node $atNode, array $assignmentBranchPath, array $positionBranchPath) use ($findControlsAtArm): bool {
            $loopClasses = [
                Node\Stmt\Do_::class,
                Node\Stmt\For_::class,
                Node\Stmt\Foreach_::class,
                Node\Stmt\While_::class,
            ];
            $sharesLoopAtArm = static function (string $controlClass, string $assignmentArm, string $positionArm) use ($findControlsAtArm, $assignment, $atNode, $assignmentBranchPath, $positionBranchPath): bool {
                $assignmentLoops = $findControlsAtArm($assignment, $assignmentBranchPath, $controlClass, $assignmentArm);
                $positionLoops = $findControlsAtArm($atNode, $positionBranchPath, $controlClass, $positionArm);

                foreach ($assignmentLoops as $loop) {
                    if (in_array($loop, $positionLoops, true)) {
                        return true;
                    }
                }

                return false;
            };

            foreach ($loopClasses as $controlClass) {
                if ($sharesLoopAtArm($controlClass, 'stmts', 'stmts')) {
                    return true;
                }
            }

            foreach ([
                [Node\Stmt\Do_::class, 'cond'],
                [Node\Stmt\While_::class, 'cond'],
                [Node\Stmt\For_::class, 'cond'],
                [Node\Stmt\For_::class, 'loop'],
            ] as [$controlClass, $positionArm]) {
                if ($sharesLoopAtArm($controlClass, 'stmts', $positionArm)) {
                    return true;
                }
            }

            if ($sharesLoopAtArm(Node\Stmt\Do_::class, 'cond', 'stmts')) {
                return true;
            }

            return false;
        };
        $assignmentArmCanReachPosition = static function (Node $assignment, Node $atNode, array $assignmentBranchPath, array $positionBranchPath) use ($loopAssignmentCanReachPosition): bool {
            if ($loopAssignmentCanReachPosition($assignment, $atNode, $assignmentBranchPath, $positionBranchPath)) {
                return true;
            }

            foreach ($assignmentBranchPath as $assignmentBranch) {
                $assignmentSeparator = strpos($assignmentBranch, ':');

                if ($assignmentSeparator === false) {
                    continue;
                }

                $tryCatchId = substr($assignmentBranch, 0, $assignmentSeparator);
                $assignmentArm = substr($assignmentBranch, $assignmentSeparator + 1);

                foreach ($positionBranchPath as $positionBranch) {
                    $positionSeparator = strpos($positionBranch, ':');

                    if ($positionSeparator === false || substr($positionBranch, 0, $positionSeparator) !== $tryCatchId) {
                        continue;
                    }

                    $positionArm = substr($positionBranch, $positionSeparator + 1);

                    if (($assignmentArm === 'stmts'
                            && (str_starts_with($positionArm, 'catches:arm:') || $positionArm === 'finally'))
                        || (str_starts_with($assignmentArm, 'catches:arm:') && $positionArm === 'finally')
                        || (in_array($assignmentArm, ['cond', 'expr', 'init', 'loop'], true)
                            && ($positionArm === 'stmts' || $positionArm === 'else'
                                || str_starts_with($positionArm, 'elseifs:arm:')
                                || str_starts_with($positionArm, 'cases:arm:')))) {
                        return true;
                    }
                }
            }

            $enclosingCases = static function (Node $node): array {
                $cases = [];
                $current = $node;

                while (($parent = $current->getAttribute('parent')) instanceof Node) {
                    if ($parent instanceof Node\Stmt\Case_) {
                        $cases[] = $parent;
                    }

                    $current = $parent;
                }

                return $cases;
            };
            $assignmentCases = $enclosingCases($assignment);
            $positionCases = $enclosingCases($atNode);

            foreach ($assignmentCases as $assignmentCase) {
                $switch = $assignmentCase->getAttribute('parent');

                if (! $switch instanceof Node\Stmt\Switch_) {
                    continue;
                }

                foreach ($positionCases as $positionCase) {
                    if ($positionCase->getAttribute('parent') !== $switch) {
                        continue;
                    }

                    $assignmentCaseIndex = array_search($assignmentCase, $switch->cases, true);
                    $positionCaseIndex = array_search($positionCase, $switch->cases, true);

                    if (! is_int($assignmentCaseIndex) || ! is_int($positionCaseIndex)
                        || $assignmentCaseIndex >= $positionCaseIndex) {
                        continue;
                    }

                    if (in_array(spl_object_id($assignmentCase).':cond', $assignmentBranchPath, true)) {
                        return true;
                    }

                    $terminatedBeforePosition = false;

                    for ($caseIndex = $assignmentCaseIndex; $caseIndex < $positionCaseIndex; $caseIndex++) {
                        $caseStatements = $switch->cases[$caseIndex]->stmts;
                        $lastStatement = $caseStatements === [] ? null : $caseStatements[array_key_last($caseStatements)];

                        if ($lastStatement instanceof Node\Stmt\Break_ || $lastStatement instanceof Node\Stmt\Continue_
                            || $lastStatement instanceof Node\Stmt\Return_
                            || ($lastStatement instanceof Node\Stmt\Expression
                                && ($lastStatement->expr instanceof Node\Expr\Exit_ || $lastStatement->expr instanceof Node\Expr\Throw_))) {
                            $terminatedBeforePosition = true;

                            break;
                        }
                    }

                    if (! $terminatedBeforePosition) {
                        return true;
                    }
                }
            }

            $conditionElseIf = null;
            $current = $assignment;

            while (($parent = $current->getAttribute('parent')) instanceof Node) {
                if ($parent instanceof Node\Stmt\ElseIf_
                    && in_array(spl_object_id($parent).':cond', $assignmentBranchPath, true)) {
                    $conditionElseIf = $parent;

                    break;
                }

                $current = $parent;
            }

            $containingIf = $conditionElseIf?->getAttribute('parent');

            if ($conditionElseIf instanceof Node\Stmt\ElseIf_ && $containingIf instanceof Node\Stmt\If_) {
                $conditionElseIfIndex = array_search($conditionElseIf, $containingIf->elseifs, true);

                if (is_int($conditionElseIfIndex)) {
                    $current = $atNode;

                    while (($parent = $current->getAttribute('parent')) instanceof Node) {
                        if ($parent instanceof Node\Stmt\ElseIf_ && $parent->getAttribute('parent') === $containingIf) {
                            $positionElseIfIndex = array_search($parent, $containingIf->elseifs, true);

                            if (is_int($positionElseIfIndex) && $conditionElseIfIndex < $positionElseIfIndex) {
                                return true;
                            }
                        }

                        if ($parent instanceof Node\Stmt\Else_ && $parent->getAttribute('parent') === $containingIf) {
                            return true;
                        }

                        $current = $parent;
                    }
                }
            }

            $sharedPathLength = min(count($assignmentBranchPath), count($positionBranchPath));

            for ($pathIndex = 0; $pathIndex < $sharedPathLength; $pathIndex++) {
                if ($assignmentBranchPath[$pathIndex] === $positionBranchPath[$pathIndex]) {
                    continue;
                }

                $assignmentSeparator = strpos($assignmentBranchPath[$pathIndex], ':');
                $positionSeparator = strpos($positionBranchPath[$pathIndex], ':');

                if ($assignmentSeparator !== false && $positionSeparator !== false) {
                    return substr($assignmentBranchPath[$pathIndex], 0, $assignmentSeparator)
                        !== substr($positionBranchPath[$pathIndex], 0, $positionSeparator);
                }

                return false;
            }

            return false;
        };
        $bindingsAtPosition = static function (array $scopeNodes, array $bindings, array $returnAssignmentEvidence, Node $atNode) use ($expressionEvidence, $branchPathForNode, $branchPathIsPrefix, $assignmentArmCanReachPosition, $loopAssignmentCanReachPosition): array {
            $position = $atNode->getStartFilePos();
            $branchPath = $branchPathForNode($atNode);

            foreach ($scopeNodes as $scopeMember) {
                if (! ($scopeMember instanceof Node\Expr\Assign || $scopeMember instanceof Node\Expr\AssignRef)
                    || ! $scopeMember->var instanceof Node\Expr\Variable || ! is_string($scopeMember->var->name)) {
                    continue;
                }

                $assignmentBranchPath = $branchPathForNode($scopeMember);

                if ($scopeMember->getStartFilePos() >= $position
                    && ! $loopAssignmentCanReachPosition($scopeMember, $atNode, $assignmentBranchPath, $branchPath)) {
                    continue;
                }

                if (! $branchPathIsPrefix($assignmentBranchPath, $branchPath)) {
                    if ($branchPathIsPrefix($branchPath, $assignmentBranchPath)
                        || $assignmentArmCanReachPosition($scopeMember, $atNode, $assignmentBranchPath, $branchPath)) {
                        unset($bindings[$scopeMember->var->name]);
                    }

                    continue;
                }

                $assignmentEvidence = $expressionEvidence($scopeMember->expr, $bindings);
                $returnEvidence = $returnAssignmentEvidence[spl_object_id($scopeMember)] ?? [];
                $assignmentBranchPath = $returnEvidence['assignmentBranchPath'] ?? null;
                $returnBranchPath = $returnEvidence['returnBranchPath'] ?? null;

                if (is_array($assignmentBranchPath) && is_array($returnBranchPath)
                    && $branchPathIsPrefix($assignmentBranchPath, $branchPath)
                    && ($branchPathIsPrefix($branchPath, $returnBranchPath) || $branchPathIsPrefix($returnBranchPath, $branchPath))) {
                    $assignmentEvidence['model'] = $assignmentEvidence['model'] || ($returnEvidence['model'] ?? false);
                    $assignmentEvidence['owner'] = $assignmentEvidence['owner'] || ($returnEvidence['owner'] ?? false);
                }

                if ($assignmentEvidence['model'] || $assignmentEvidence['relation'] || $assignmentEvidence['table'] || $assignmentEvidence['owner']) {
                    $bindings[$scopeMember->var->name] = $assignmentEvidence;
                } else {
                    unset($bindings[$scopeMember->var->name]);
                }
            }

            return $bindings;
        };
        $scopes = [...$ast, ...$finder->find($ast, static fn (Node $node): bool => $functionLike($node))];

        foreach ($scopes as $scopeNode) {
            if (! $scopeNode instanceof Node) {
                continue;
            }

            $scopeNodes = $collectNodes($scopeNode);
            $bindings = $callbackRelationBindings[spl_object_id($scopeNode)] ?? [];

            if (isset($relationOwnerScopes[spl_object_id($scopeNode)])) {
                $bindings['this'] = ['model' => false, 'relation' => false, 'table' => false, 'owner' => true];
            }

            $scopeReturnsModel = $functionLike($scopeNode) && is_string($model) && $model !== ''
                && $typeGuaranteesClass($scopeNode->returnType ?? null, $model);
            $scopeReturnsOwner = $functionLike($scopeNode) && is_string($relationOwner) && $relationOwner !== ''
                && $typeGuaranteesClass($scopeNode->returnType ?? null, $relationOwner);
            $returnAssignmentEvidence = [];

            if ($scopeReturnsModel || $scopeReturnsOwner) {
                foreach ($scopeNodes as $returnNode) {
                    if (! $returnNode instanceof Node\Stmt\Return_
                        || ! $returnNode->expr instanceof Node\Expr\Variable || ! is_string($returnNode->expr->name)) {
                        continue;
                    }

                    $variableName = $returnNode->expr->name;
                    $returnPosition = $returnNode->getStartFilePos();
                    $returnBranchPath = $branchPathForNode($returnNode);
                    $candidateAssignments = array_values(array_filter(
                        $scopeNodes,
                        static function (Node $candidate) use ($variableName, $returnPosition, $returnBranchPath, $branchPathForNode, $branchPathIsPrefix): bool {
                            return ($candidate instanceof Node\Expr\Assign || $candidate instanceof Node\Expr\AssignRef)
                                && $candidate->var instanceof Node\Expr\Variable
                                && $candidate->var->name === $variableName
                                && $candidate->getStartFilePos() < $returnPosition
                                && $branchPathIsPrefix($branchPathForNode($candidate), $returnBranchPath);
                        },
                    ));
                    usort($candidateAssignments, static fn (Node $left, Node $right): int => $right->getStartFilePos() <=> $left->getStartFilePos());

                    foreach ($candidateAssignments as $candidateAssignment) {
                        $assignmentPosition = $candidateAssignment->getStartFilePos();
                        $assignmentBranchPath = $branchPathForNode($candidateAssignment);
                        $hasInterveningAssignment = false;

                        foreach ($scopeNodes as $possibleAssignment) {
                            if (! ($possibleAssignment instanceof Node\Expr\Assign || $possibleAssignment instanceof Node\Expr\AssignRef)
                                || ! $possibleAssignment->var instanceof Node\Expr\Variable
                                || $possibleAssignment->var->name !== $variableName
                                || $possibleAssignment->getStartFilePos() <= $assignmentPosition
                                || $possibleAssignment->getStartFilePos() >= $returnPosition) {
                                continue;
                            }

                            $possibleAssignmentBranchPath = $branchPathForNode($possibleAssignment);
                            $pathCanReachReturn = $branchPathIsPrefix($possibleAssignmentBranchPath, $returnBranchPath)
                                || $branchPathIsPrefix($returnBranchPath, $possibleAssignmentBranchPath);

                            if ($pathCanReachReturn) {
                                $hasInterveningAssignment = true;

                                break;
                            }
                        }

                        if ($hasInterveningAssignment) {
                            continue;
                        }

                        $assignmentId = spl_object_id($candidateAssignment);
                        $returnAssignmentEvidence[$assignmentId] = [
                            'model' => ($returnAssignmentEvidence[$assignmentId]['model'] ?? false) || $scopeReturnsModel,
                            'owner' => ($returnAssignmentEvidence[$assignmentId]['owner'] ?? false) || $scopeReturnsOwner,
                            'assignmentBranchPath' => $assignmentBranchPath,
                            'returnBranchPath' => $returnBranchPath,
                        ];

                        break;
                    }
                }
            }

            foreach ($scopeNodes as $scopeMember) {
                if ($scopeMember instanceof Node\Param && $scopeMember->var instanceof Node\Expr\Variable
                    && is_string($scopeMember->var->name)
                    && ($typeMatchesModel($scopeMember->type)
                        || $typeMatchesRelationOwner($scopeMember->type)
                        || (is_string($relationOwner) && $methodParamMatchesClass($scopeNode, $scopeMember->var->name, $relationOwner)))) {
                    $bindings[$scopeMember->var->name] = [
                        'model' => $typeMatchesModel($scopeMember->type),
                        'relation' => false,
                        'table' => false,
                        'owner' => $typeMatchesRelationOwner($scopeMember->type)
                            || (is_string($relationOwner) && $methodParamMatchesClass($scopeNode, $scopeMember->var->name, $relationOwner)),
                    ];
                }

            }

            foreach ($scopeNodes as $scopeMember) {
                if (! ($scopeMember instanceof Node\Expr\MethodCall || $scopeMember instanceof Node\Expr\NullsafeMethodCall)
                    || ! $scopeMember->name instanceof Node\Identifier
                    || strtolower($scopeMember->name->toString()) !== 'each') {
                    continue;
                }

                $iterationBindings = $bindingsAtPosition($scopeNodes, $bindings, $returnAssignmentEvidence, $scopeMember);
                $ownerEvidence = $expressionEvidence($scopeMember->var, $iterationBindings);

                if (! $ownerEvidence['relation']) {
                    continue;
                }

                foreach ($scopeMember->args as $argument) {
                    $callback = $argument->value;

                    if (! $callback instanceof Node\Expr\Closure && ! $callback instanceof Node\Expr\ArrowFunction) {
                        continue;
                    }

                    $parameter = $callback->params[0]->var ?? null;

                    if ($parameter instanceof Node\Expr\Variable && is_string($parameter->name)) {
                        $callbackRelationBindings[spl_object_id($callback)][$parameter->name] = [
                            'model' => false,
                            'relation' => true,
                            'table' => false,
                            'owner' => false,
                        ];
                    }
                }
            }

            foreach ($findNestedFunctionScopes($scopeNode) as $nestedScope) {
                $capturedBindings = $bindingsAtPosition($scopeNodes, $bindings, $returnAssignmentEvidence, $nestedScope);

                if ($nestedScope instanceof Node\Expr\Closure) {
                    foreach ($nestedScope->uses as $capture) {
                        if ($capture->byRef || ! $capture->var instanceof Node\Expr\Variable || ! is_string($capture->var->name)
                            || ! isset($capturedBindings[$capture->var->name])) {
                            continue;
                        }

                        $callbackRelationBindings[spl_object_id($nestedScope)][$capture->var->name] = $capturedBindings[$capture->var->name];
                    }

                    continue;
                }

                if (! $nestedScope instanceof Node\Expr\ArrowFunction) {
                    continue;
                }

                $parameterNames = [];

                foreach ($nestedScope->params as $parameter) {
                    if ($parameter->var instanceof Node\Expr\Variable && is_string($parameter->var->name)) {
                        $parameterNames[$parameter->var->name] = true;
                    }
                }

                foreach ($capturedBindings as $variableName => $evidence) {
                    if (! isset($parameterNames[$variableName])) {
                        $callbackRelationBindings[spl_object_id($nestedScope)][$variableName] = $evidence;
                    }
                }
            }

            $mutations = array_values(array_filter($scopeNodes, $isMutator));

            foreach ($mutations as $mutation) {
                $mutationBindings = $bindingsAtPosition($scopeNodes, $bindings, $returnAssignmentEvidence, $mutation);

                $receiver = $mutation instanceof Node\Expr\StaticCall ? $mutation->class : $mutation->var;
                $evidence = $expressionEvidence($receiver, $mutationBindings);

                if ($mutation instanceof Node\Expr\StaticCall && isset($parentDeleteMutations[spl_object_id($mutation)])) {
                    $evidence['model'] = true;
                }

                if (is_string($model) && $model !== '' && isset($modelTables[$model]) && $modelTables[$model] !== $target) {
                    continue;
                }

                if (is_string($relation) && $relation !== '') {
                    if ($evidence['relation']) {
                        return true;
                    }

                    continue;
                }

                if (is_string($model) && $model !== '' && $evidence['model']) {
                    return true;
                }
            }
        }

        return false;
    };
    $staleWriteExceptions = [];

    foreach ($config['exceptions'] as $exception) {
        if (! is_array($exception)) {
            throw new RuntimeException('Exception must be an object with rule/file/target/reason/owner/expires.');
        }
        foreach (['rule', 'file', 'target', 'reason', 'owner', 'expires'] as $field) {
            if (! is_string($exception[$field] ?? null) || trim($exception[$field]) === '') {
                throw new RuntimeException("Exception requires nonempty {$field}");
            }
        }
        if (! in_array($exception['rule'], $suppressible, true) || strpbrk($exception['file'].$exception['target'], '*?') !== false) {
            throw new RuntimeException('Exceptions require an allowed rule and exact file/target (no wildcard).');
        }
        if (str_starts_with($exception['target'], 'table:')
            && (! is_string($exception['model'] ?? null) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $exception['model']))) {
            throw new RuntimeException('Table write exceptions require an exact model class.');
        }
        if ($exception['target'] === 'store:unknown') {
            throw new RuntimeException('Unknown store exceptions are not allowed.');
        }
        $exceptionFile = str_replace('\\', '/', $exception['file']);
        $expectedSanctumRelation = $sanctumExceptionRelations[$exceptionFile] ?? null;
        $isSanctumCandidate = array_key_exists('model_provider', $exception)
            || (($exception['model'] ?? null) === 'Laravel\\Sanctum\\PersonalAccessToken'
                && $exception['target'] === 'table:unknown')
            || ($expectedSanctumRelation !== null
                && ($exception['relation'] ?? null) === $expectedSanctumRelation);

        if ($isSanctumCandidate
            && ($exception['model_provider'] ?? null) !== 'sanctum') {
            throw new RuntimeException('The exact Sanctum token exception requires model_provider: sanctum.');
        }

        if ($expectedSanctumRelation === null && array_key_exists('model_provider', $exception)) {
            throw new RuntimeException('Sanctum model provider metadata is not valid for this exception file.');
        }

        if ($isSanctumCandidate
            && ($exception['target'] !== 'table:unknown'
                || ! is_string($exception['model'] ?? null)
                || $exception['model'] !== $effectiveSanctumTokenModel
                || ($exception['relation'] ?? null) !== $expectedSanctumRelation
                || ($exception['relation_owner'] ?? null) !== 'App\\Models\\User')) {
            throw new RuntimeException('Sanctum token model metadata is only valid on its exact User token exception.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $exception['expires']);
        if (! $date || $date->format('Y-m-d') !== $exception['expires'] || $exception['expires'] < gmdate('Y-m-d')) {
            throw new RuntimeException('Invalid/expired exception: '.$exception['file'].' '.$exception['target']);
        }
        if (in_array($exception['rule'], $writeSuppressible, true)) {
            if (array_key_exists('relation', $exception)
                && (! is_string($exception['relation']) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $exception['relation'])
                    || ! str_starts_with($exception['target'], 'table:')
                    || ! is_string($exception['relation_owner'] ?? null)
                    || ! preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $exception['relation_owner']))) {
                throw new RuntimeException('Relation-backed write exceptions require an exact method name, owner class and table target.');
            }

            if (array_key_exists('relation_owner', $exception) && ! array_key_exists('relation', $exception)) {
                throw new RuntimeException('Relation owner metadata requires a relation-backed write exception.');
            }

            if (! $writeExceptionIsConsumed($exception)) {
                $model = is_string($exception['model'] ?? null) ? ' model '.$exception['model'] : '';

                $staleWriteExceptions[] = 'Remove stale exception: '.$exception['file'].' '.$exception['target'].$model;
            }

            continue;
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

    if ($staleWriteExceptions !== []) {
        throw new RuntimeException(implode("\n", $staleWriteExceptions));
    }
    foreach ($readAdapterUsage as $adapterFile => $used) {
        if (! $used) {
            throw new RuntimeException('Remove stale read adapter: '.$adapterFile);
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Architecture configuration/parser error: '.$error->getMessage()."\n");
    exit(2);
}

$findings = array_values($findings);
if (isset($options['json'])) {
    echo json_encode(['schema' => 1, 'findings' => $findings, 'features' => array_keys($features)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
} else {
    foreach ($findings as $finding) {
        echo "{$finding['file']}:{$finding['line']} [{$finding['rule']}] {$finding['target']}: {$finding['message']}\n";
    }
    echo count($findings).' architecture violation(s); '.count($features)." feature(s) checked.\n";
}
exit($findings !== [] ? 1 : 0);
