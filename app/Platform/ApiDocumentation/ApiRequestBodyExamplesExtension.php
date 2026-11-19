<?php

declare(strict_types=1);

namespace App\Platform\ApiDocumentation;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\GeneratorConfig;
use Dedoc\Scramble\Infer;
use Dedoc\Scramble\Support\Generator\MediaType;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Str;

final class ApiRequestBodyExamplesExtension extends OperationExtension
{
    public function __construct(
        Infer $infer,
        TypeTransformer $openApiTransformer,
        GeneratorConfig $config,
        private readonly RequestExampleRegistry $examples,
    ) {
        parent::__construct($infer, $openApiTransformer, $config);
    }

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        if (! $operation->requestBodyObject instanceof RequestBodyObject) {
            return;
        }

        $example = $this->resolveExample($routeInfo, $operation);

        if (! is_array($example)) {
            return;
        }

        $requestBodyObject = $operation->requestBodyObject;

        foreach (array_keys($requestBodyObject->content) as $mediaType) {
            $content = $requestBodyObject->getContent($mediaType);
            $content = $content instanceof Reference ? $content->resolve() : $content;

            if (! $content instanceof MediaType) {
                continue;
            }

            $content = clone $content;
            $content->setExample($example);
            $requestBodyObject->addContent($mediaType, $content);

            $resolvedSchema = $content->schema instanceof Reference ? $content->schema->resolve() : $content->schema;

            if ($resolvedSchema instanceof Schema) {
                $resolvedSchema->type->example($example);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveExample(RouteInfo $routeInfo, Operation $operation): ?array
    {
        $method = strtolower($operation->method);
        $candidates = array_unique([
            $this->normalizedOperationPath($operation),
            $this->normalizedRoutePath($routeInfo),
            $this->canonicalizeWildcardNames($this->normalizedOperationPath($operation)),
            $this->canonicalizeWildcardNames($this->normalizedRoutePath($routeInfo)),
        ]);

        foreach ($candidates as $path) {
            if ($path === '') {
                continue;
            }

            $example = $this->examples->find("{$method} {$path}");

            if (is_array($example)) {
                return $example;
            }
        }

        return null;
    }

    private function normalizedRoutePath(RouteInfo $routeInfo): string
    {
        $uri = trim($routeInfo->route->uri(), '/');
        $apiPath = trim((string) config('scramble.api_path', 'api/v1'), '/');

        if ($apiPath !== '' && Str::startsWith($uri, $apiPath.'/')) {
            return Str::after($uri, $apiPath.'/');
        }

        return $uri;
    }

    private function normalizedOperationPath(Operation $operation): string
    {
        $path = trim($operation->path, '/');
        $apiPath = trim((string) config('scramble.api_path', 'api/v1'), '/');

        if ($apiPath !== '' && Str::startsWith($path, $apiPath.'/')) {
            return Str::after($path, $apiPath.'/');
        }

        return $path;
    }

    private function canonicalizeWildcardNames(string $path): string
    {
        return str_replace(['{saved_search}'], ['{savedSearch}'], $path);
    }
}
