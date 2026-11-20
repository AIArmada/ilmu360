<?php

declare(strict_types=1);

namespace App\Platform\ApiDocumentation\Contributors;

use App\Platform\ApiDocumentation\Contracts\DocumentSchemaContributor;
use App\Platform\ApiDocumentation\Support\OperationSchemaPatcher;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\Combined\AnyOf;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\MixedType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Locks the externally published OpenAPI shapes that predate the ownership
 * refactor.
 *
 * Scramble infers response schemas from the concrete service return types.
 * Owner decomposition made several of those inferences richer without changing
 * any runtime payload (the API parity suites prove byte-identical JSON). The
 * published contract must stay byte-identical too, so the drifted shapes are
 * normalized here, in one reviewable place, instead of weakening service types
 * or scattering schema-only annotations through the codebase.
 */
final readonly class PublishedContractSchemasContributor implements DocumentSchemaContributor
{
    public function __construct(private OperationSchemaPatcher $operations) {}

    public function supports(OpenApi $document, OpenApiContext $context): bool
    {
        return true;
    }

    /**
     * @return array<string, Schema>
     */
    public function schemas(Components $components): array
    {
        return [];
    }

    public function patch(OpenApi $document, Components $components): void
    {
        $this->normalizeAdminResourceMetadata($document);
        $this->normalizeContributionCreates($document, $components);
        $this->normalizeEventEngagement($document);
        $this->normalizeInstitutionWorkspace($document);
        $this->normalizeAccountEventLists($document);
        $this->normalizeEntityDetails($document);
    }

    private function normalizeAdminResourceMetadata(OpenApi $document): void
    {
        $operations = [
            ['/admin/{resourceKey}', 'get', 200],
            ['/admin/{resourceKey}/batch', 'put', 200],
            ['/admin/{resourceKey}/meta', 'get', 200],
            ['/admin/{resourceKey}/schema', 'get', 200],
            ['/admin/{resourceKey}/{recordKey}', 'get', 200],
            ['/admin/{resourceKey}/{recordKey}', 'put', 200],
            ['/admin/{resourceKey}/{recordKey}/relations/{relation}', 'get', 200],
        ];

        foreach ($operations as [$path, $method, $status]) {
            $schema = $this->responseSchema($document, $path, $method, $status);

            if (! $schema instanceof Schema) {
                continue;
            }

            $this->replaceNamedObjects($schema->type, ['resource', 'related_resource']);
        }
    }

    private function normalizeContributionCreates(OpenApi $document, Components $components): void
    {
        $institution = $this->responseSchema($document, '/contributions/institutions', 'post', 201);

        if ($institution instanceof Schema) {
            $this->setProperty($institution, ['data', 'institution', 'id'], new MixedType);
            $status = $this->propertyAt($institution->type, ['data', 'institution', 'status']);

            if ($status instanceof StringType) {
                $status->enum = ['pending', 'verified', 'rejected', 'inactive'];
            }
        }

        $person = $this->responseSchema($document, '/contributions/persons', 'post', 201);

        if ($person instanceof Schema) {
            $this->setProperty($person, ['data', 'person', 'id'], new MixedType);
            $this->setProperty($person, ['data', 'person', 'slug'], (new StringType)->nullable(true));
        }

        $this->addResponseReference($document, '/contributions/institutions', 'post', $components);
        $this->addResponseReference($document, '/contributions/persons', 'post', $components);
    }

    private function normalizeEventEngagement(OpenApi $document): void
    {
        foreach ([
            ['/events/{event}/going', 'delete', 200],
            ['/events/{event}/saved', 'delete', 200],
            ['/events/{event}/registrations', 'post', 201],
            ['/events/{event}/me', 'get', 200],
        ] as [$path, $method, $status]) {
            $schema = $this->responseSchema($document, $path, $method, $status);

            if ($schema instanceof Schema) {
                $this->setProperty($schema, ['data'], $this->genericObject());
            }
        }
    }

    private function normalizeInstitutionWorkspace(OpenApi $document): void
    {
        $schema = $this->responseSchema($document, '/institution-workspace', 'get', 200);

        if (! $schema instanceof Schema) {
            return;
        }

        $venue = (new ObjectType)
            ->addProperty('id', new StringType)
            ->addProperty('name', new StringType)
            ->setRequired(['id', 'name'])
            ->nullable(true);

        $item = (new ObjectType)
            ->addProperty('id', new MixedType)
            ->addProperty('title', new StringType)
            ->addProperty('slug', new StringType)
            ->addProperty('status', new StringType)
            ->addProperty('visibility', new StringType)
            ->addProperty('starts_at', (new StringType)->nullable(true))
            ->addProperty('registrations_count', new IntegerType)
            ->addProperty('venue', $venue)
            ->setRequired([
                'id',
                'title',
                'slug',
                'status',
                'visibility',
                'starts_at',
                'registrations_count',
                'venue',
            ]);

        $this->setProperty($schema, ['data', 'events'], $this->arrayOf($item));
    }

    private function normalizeAccountEventLists(OpenApi $document): void
    {
        foreach (['/me/events/going', '/me/events/saved'] as $path) {
            $schema = $this->responseSchema($document, $path, 'get', 200);

            if ($schema instanceof Schema) {
                $this->setProperty(
                    $schema,
                    ['data'],
                    (new ObjectType)->additionalProperties($this->arrayOf(new MixedType)),
                );
            }

            $operation = $this->operations->findOperation($document, $path, 'get');

            if ($operation instanceof \Dedoc\Scramble\Support\Generator\Operation) {
                $operation->parameters = array_values(array_filter(
                    $operation->parameters,
                    static fn (mixed $parameter): bool => ! $parameter instanceof Parameter || $parameter->name !== 'page',
                ));
            }
        }
    }

    private function normalizeEntityDetails(OpenApi $document): void
    {
        $details = [
            ['/references/{referenceKey}', 'reference'],
            ['/venues/{venueKey}', 'venue'],
            ['/series/{series}', 'series'],
        ];

        foreach ($details as [$path, $property]) {
            $schema = $this->responseSchema($document, $path, 'get', 200);

            if (! $schema instanceof Schema) {
                continue;
            }

            $this->setProperty($schema, ['data', $property], $this->genericObject());
            $this->setProperty($schema, ['data', 'upcoming_events'], $this->arrayOf(new StringType));
            $this->setProperty($schema, ['data', 'past_events'], $this->arrayOf(new StringType));
        }
    }

    private function responseSchema(
        OpenApi $document,
        string $path,
        string $method,
        int|string $status,
    ): ?Schema {
        $operation = $this->operations->findOperation($document, $path, $method);

        if (! $operation instanceof \Dedoc\Scramble\Support\Generator\Operation) {
            return null;
        }

        foreach ($operation->responses ?? [] as $response) {
            if (! $response instanceof Response || (string) $response->code !== (string) $status) {
                continue;
            }

            $content = $response->content['application/json'] ?? null;

            if ($content instanceof Schema) {
                return $content;
            }

            if ($content instanceof Reference) {
                $resolved = $content->resolve();

                return $resolved instanceof Schema ? $resolved : null;
            }
        }

        return null;
    }

    private function addResponseReference(
        OpenApi $document,
        string $path,
        string $method,
        Components $components,
    ): void {
        $operation = $this->operations->findOperation($document, $path, $method);

        if (! $operation instanceof \Dedoc\Scramble\Support\Generator\Operation) {
            return;
        }

        foreach ($operation->responses ?? [] as $response) {
            if ($response instanceof Response && (string) $response->code === '404') {
                return;
            }
        }

        $componentName = '\\'.ModelNotFoundException::class;

        if (! array_key_exists($componentName, $components->responses)) {
            foreach (array_keys($components->responses) as $name) {
                if (str_ends_with((string) $name, 'ModelNotFoundException')) {
                    $componentName = (string) $name;

                    break;
                }
            }
        }

        $operation->addResponse(new Reference('responses', $componentName, $components));
    }

    /**
     * @param  list<string>  $names
     */
    private function replaceNamedObjects(Type $type, array $names): void
    {
        if ($type instanceof ObjectType) {
            foreach ($type->properties as $name => $property) {
                if (! $property instanceof Type) {
                    continue;
                }

                if (in_array($name, $names, true) && $property instanceof ObjectType) {
                    $type->properties[$name] = (new ObjectType)->nullable($property->nullable);

                    continue;
                }

                $this->replaceNamedObjects($property, $names);
            }

            if ($type->additionalProperties instanceof Type) {
                $this->replaceNamedObjects($type->additionalProperties, $names);
            }

            return;
        }

        if ($type instanceof ArrayType && $type->items instanceof Type) {
            $this->replaceNamedObjects($type->items, $names);

            return;
        }

        if ($type instanceof AnyOf) {
            foreach ($type->items as $item) {
                $this->replaceNamedObjects($item, $names);
            }
        }
    }

    /**
     * @param  list<string>  $keys
     */
    private function setProperty(Schema $schema, array $keys, Type $value): void
    {
        $this->setOnType($schema->type, $keys, $value);
    }

    /**
     * @param  list<string>  $keys
     */
    private function setOnType(Type $type, array $keys, Type $value): void
    {
        if ($type instanceof AnyOf) {
            foreach ($type->items as $item) {
                $this->setOnType($item, $keys, $value);
            }

            return;
        }

        if (! $type instanceof ObjectType || $keys === []) {
            return;
        }

        $key = array_shift($keys);

        if ($keys === []) {
            $type->properties[$key] = $value;

            return;
        }

        $next = $type->properties[$key] ?? null;

        if ($next instanceof Type) {
            $this->setOnType($next, $keys, $value);
        }
    }

    /**
     * @param  list<string>  $keys
     */
    private function propertyAt(Type $type, array $keys): ?Type
    {
        if ($type instanceof AnyOf) {
            foreach ($type->items as $item) {
                $property = $this->propertyAt($item, $keys);

                if ($property instanceof Type) {
                    return $property;
                }
            }

            return null;
        }

        if (! $type instanceof ObjectType || $keys === []) {
            return $type;
        }

        $key = array_shift($keys);
        $next = $type->properties[$key] ?? null;

        return $next instanceof Type ? $this->propertyAt($next, $keys) : null;
    }

    private function genericObject(): ObjectType
    {
        return (new ObjectType)->additionalProperties(new MixedType);
    }

    private function arrayOf(Type $item): ArrayType
    {
        return (new ArrayType)->setItems($item);
    }
}
