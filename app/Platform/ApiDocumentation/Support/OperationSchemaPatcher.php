<?php

declare(strict_types=1);

namespace App\Platform\ApiDocumentation\Support;

use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response as OpenApiResponse;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\NumberType;

/**
 * Applies generated-operation patches shared by owner document contributors.
 */
final class OperationSchemaPatcher
{
    public function replaceJsonResponseSchema(
        OpenApi $document,
        string $path,
        string $method,
        int $status,
        Components $components,
        string $schemaName,
        string $description,
    ): void {
        $operation = $this->findOperation($document, $path, $method);

        if (! $operation instanceof Operation) {
            return;
        }

        foreach ($operation->responses ?? [] as $response) {
            if (! $response instanceof OpenApiResponse || (string) $response->code !== (string) $status) {
                continue;
            }

            $response
                ->setDescription($description)
                ->setContent('application/json', $components->getSchemaReference($schemaName));

            return;
        }
    }

    public function replaceOperationResponseSchema(OpenApi $document, string $path, string $method, Reference $schema): void
    {
        $operation = $this->findOperation($document, $path, $method);

        if (! $operation instanceof Operation) {
            return;
        }

        foreach ($operation->responses ?? [] as $response) {
            if (! $response instanceof OpenApiResponse || $response->code !== 200) {
                continue;
            }

            $response->setContent('application/json', $schema);
        }
    }

    public function replaceNumericQueryParameter(Operation $operation, string $name, string $description, float $example): void
    {
        foreach ($operation->parameters as $parameter) {
            if (! $parameter instanceof Parameter || $parameter->name !== $name) {
                continue;
            }

            $parameter
                ->setSchema(Schema::fromType(new NumberType))
                ->description($description)
                ->example($example);

            return;
        }

        $operation->addParameters([
            Parameter::make($name, 'query')
                ->required(false)
                ->setSchema(Schema::fromType(new NumberType))
                ->description($description)
                ->example($example),
        ]);
    }

    public function findOperation(OpenApi $document, string $path, string $method): ?Operation
    {
        $normalizedPath = trim($path, '/');
        $normalizedMethod = strtolower($method);

        foreach ($document->paths as $documentPath) {
            if (trim($documentPath->path, '/') !== $normalizedPath) {
                continue;
            }

            return $documentPath->operations[$normalizedMethod] ?? null;
        }

        return null;
    }

    public function nullableReference(Components $components, string $name): Reference
    {
        return (clone $components->getSchemaReference($name))->nullable(true);
    }
}
