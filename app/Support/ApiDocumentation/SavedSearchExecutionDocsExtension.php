<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation;

use App\Models\Event;
use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\RouteInfo;
use Dedoc\Scramble\Support\Type\ObjectType as InferObjectType;
use Illuminate\Support\Str;

final class SavedSearchExecutionDocsExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        if ($routeInfo->method !== 'post' || ! Str::endsWith($routeInfo->route->uri(), 'saved-searches/{savedSearch}/execute')) {
            return;
        }

        $eventReference = $this->openApiTransformer->transform(new InferObjectType(Event::class));

        $pagination = (new ObjectType)
            ->addProperty('page', new IntegerType)
            ->addProperty('per_page', new IntegerType)
            ->addProperty('total', new IntegerType)
            ->addProperty('has_more', new BooleanType)
            ->addProperty('next_page', (new IntegerType)->nullable(true))
            ->setRequired(['page', 'per_page', 'total', 'has_more', 'next_page']);

        $envelope = (new ObjectType)
            ->addProperty('data', (new ArrayType)->setItems($eventReference))
            ->addProperty(
                'meta',
                (new ObjectType)
                    ->addProperty('pagination', $pagination)
                    ->setRequired(['pagination']),
            )
            ->setRequired(['data', 'meta']);

        foreach ($operation->responses ?? [] as $response) {
            if ($response instanceof Reference) {
                $response = $response->resolve();
            }

            if (! $response instanceof Response || (string) $response->code !== '200') {
                continue;
            }

            $response->setContent('application/json', Schema::fromType($envelope));

            return;
        }
    }
}
