<?php

declare(strict_types=1);

namespace App\Features\Discovery\EntryPoints\Mcp\Tools;

use App\Features\Discovery\Services\CatalogDispatcher;
use App\Models\User;
use App\Platform\Mcp\Tools\Member\AbstractMemberTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

abstract class EventSubmissionLookupTool extends AbstractMemberTool
{
    public function __construct(protected readonly CatalogDispatcher $catalogs) {}

    final public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $member = $this->authorizeMember($request);
            $arguments = $this->validateArguments($request, [
                'query' => ['required', 'string', 'max:255'],
                'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            ]);

            return [
                'data' => [
                    'results' => $this->find($member, trim((string) $arguments['query']), (int) ($arguments['limit'] ?? 10)),
                ],
            ];
        });
    }

    #[\Override]
    final public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->min(1)->max(255)->required()->description('Name or part of the name as shown on the flyer.'),
            'limit' => $schema->integer()->min(1)->max(50)->default(10)->description('Maximum number of matches to return.'),
        ];
    }

    #[\Override]
    final public function outputSchema(JsonSchema $schema): array
    {
        return [
            'data' => $schema->object([
                'results' => $schema->array()->items($schema->object([
                    'id' => $schema->string()->format('uuid')->required(),
                    'label' => $schema->string()->required(),
                ]))->required(),
            ])->required(),
        ];
    }

    /** @return list<array{id: string, label: string}> */
    abstract protected function find(User $member, string $query, int $limit): array;
}
