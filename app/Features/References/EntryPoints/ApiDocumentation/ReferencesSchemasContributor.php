<?php

declare(strict_types=1);

namespace App\Features\References\EntryPoints\ApiDocumentation;

use App\Platform\ApiDocumentation\Contracts\DocumentSchemaContributor;
use App\Platform\ApiDocumentation\Support\OperationSchemaPatcher;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/**
 * Documents reference directory items and the reference directory response envelope.
 */
final readonly class ReferencesSchemasContributor implements DocumentSchemaContributor
{
    public function __construct(
        private OperationSchemaPatcher $operations,
    ) {}

    public function supports(OpenApi $document, OpenApiContext $context): bool
    {
        return true;
    }

    /**
     * @return array<string, Schema>
     */
    public function schemas(Components $components): array
    {
        return [
            'ReferenceListItem' => $this->referenceListItemSchema(),
            'ReferenceDirectoryItem' => $this->referenceListItemSchema(sparse: true),
            'ReferenceDirectoryResponse' => $this->referenceDirectoryResponseSchema($components),
            'ReferenceDirectorySparseResponse' => $this->referenceDirectoryResponseSchema($components, 'ReferenceDirectoryItem'),
        ];
    }

    public function patch(OpenApi $document, Components $components): void
    {
        $this->operations->replaceOperationResponseSchema($document, 'references', 'get', $components->getSchemaReference('ReferenceDirectorySparseResponse'));
    }

    private function referenceListItemSchema(bool $sparse = false): Schema
    {
        $type = (new ObjectType)
            ->addProperty('id', new StringType)
            ->addProperty('slug', new StringType)
            ->addProperty('title', new StringType)
            ->addProperty('display_title', new StringType)
            ->addProperty('authors', (new ArrayType)->setItems(
                (new ObjectType)
                    ->addProperty('id', new StringType)
                    ->addProperty('name', new StringType)
                    ->addProperty('slug', new StringType)
            ))
            ->addProperty('author_ids', (new ArrayType)->setItems(new StringType))
            ->addProperty('type', (new StringType)->nullable(true))
            ->addProperty('parent_id', (new StringType)->nullable(true))
            ->addProperty('record_kind', new StringType)
            ->addProperty('edition_number', (new IntegerType)->nullable(true))
            ->addProperty('edition_label', (new StringType)->nullable(true))
            ->addProperty('isbn', (new StringType)->nullable(true))
            ->addProperty('language', (new StringType)->nullable(true))
            ->addProperty('language_label', (new StringType)->nullable(true))
            ->addProperty('url', (new StringType)->nullable(true))

            ->addProperty('part_type', (new StringType)->nullable(true))
            ->addProperty('part_number', (new StringType)->nullable(true))
            ->addProperty('part_label', (new StringType)->nullable(true))
            ->addProperty('is_part', new BooleanType)
            ->addProperty('publisher', (new StringType)->nullable(true))
            ->addProperty('publication_year', (new StringType)->nullable(true))
            ->addProperty('status', new StringType)
            ->addProperty('events_count', new IntegerType)
            ->addProperty('front_cover_url', (new StringType)->nullable(true))
            ->addProperty('is_following', new BooleanType)
            ->addProperty('verified_by', (new StringType)->nullable(true));

        if (! $sparse) {
            $type->setRequired([
                'id',
                'slug',
                'title',
                'display_title',
                'authors',
                'author_ids',
                'type',
                'parent_id',
                'record_kind',
                'edition_number',
                'edition_label',
                'isbn',
                'language',
                'language_label',
                'url',

                'part_type',
                'part_number',
                'part_label',
                'is_part',
                'publisher',
                'publication_year',
                'status',
                'events_count',
                'front_cover_url',
                'is_following',
            ]);
        }

        return Schema::fromType($type);
    }

    private function referenceDirectoryResponseSchema(Components $components, string $itemSchema = 'ReferenceListItem'): Schema
    {
        return Schema::fromType(
            (new ObjectType)
                ->addProperty('data', (new ArrayType)->setItems($components->getSchemaReference($itemSchema)))
                ->addProperty('meta', $this->referenceDirectoryMetaType())
                ->setRequired(['data', 'meta']),
        );
    }

    private function referenceDirectoryMetaType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty(
                'pagination',
                (new ObjectType)
                    ->addProperty('page', new IntegerType)
                    ->addProperty('per_page', new IntegerType)
                    ->addProperty('total', new IntegerType)
                    ->setRequired(['page', 'per_page', 'total']),
            )
            ->addProperty(
                'following',
                (new ObjectType)
                    ->addProperty('total', new IntegerType)
                    ->setRequired(['total']),
            )
            ->addProperty(
                'cache',
                (new ObjectType)
                    ->addProperty('version', new StringType)
                    ->setRequired(['version']),
            )
            ->setRequired(['pagination', 'following', 'cache']);
    }
}
