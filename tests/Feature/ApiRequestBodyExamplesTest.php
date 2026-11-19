<?php

declare(strict_types=1);

use App\Platform\ApiDocumentation\ApiRequestBodyExamplesExtension;
use App\Platform\ApiDocumentation\RequestExampleRegistry;
use Dedoc\Scramble\GeneratorConfig;
use Dedoc\Scramble\Infer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\Example;
use Dedoc\Scramble\Support\Generator\MediaType;
use Dedoc\Scramble\Support\Generator\MissingValue;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Routing\Route;

it('serializes route examples while preserving native request and media metadata', function (string $serializer, string $mediaType): void {
    $schema = Schema::fromType(new ObjectType);
    $content = new MediaType(schema: $schema, examples: ['retained' => new Example(value: ['marker' => 'kept'])]);
    $content->setExtensionProperty('client-policy', 'retained');
    $body = RequestBodyObject::make()->description('Login payload')->required()->addContent($mediaType, $content);
    $body->setExtensionProperty('audit-policy', 'retained');
    $operation = Operation::make('post')->addRequestBodyObject($body);
    $operation->path = 'api/v1/auth/login';
    $route = new Route('POST', 'api/v1/auth/login', static fn (): null => null);
    $config = new GeneratorConfig;
    $infer = app(Infer::class);
    $extension = new ApiRequestBodyExamplesExtension($infer, new TypeTransformer($infer, new OpenApiContext(new OpenApi, $config)), $config, app(RequestExampleRegistry::class));

    $extension->handle($operation, new RouteInfo($route, 'POST'));
    $serialized = $operation->requestBodyObject->{$serializer}();

    expect($serialized['description'])->toBe('Login payload')
        ->and($serialized['required'])->toBeTrue()
        ->and($serialized['x-audit-policy'])->toBe('retained')
        ->and($serialized['content'][$mediaType]['example']['login'])->toBe('superadmin@ilmu360.com')
        ->and($serialized['content'][$mediaType]['examples']['retained']['value'])->toBe(['marker' => 'kept'])
        ->and($serialized['content'][$mediaType]['x-client-policy'])->toBe('retained')
        ->and($serialized['content'][$mediaType]['schema']['examples'][0]['login'])->toBe('superadmin@ilmu360.com')
        ->and($content->example)->toBeInstanceOf(MissingValue::class);
})->with([
    'OpenAPI 3.1' => ['serializeAs31'],
    'OpenAPI 3.2' => ['serializeAs32'],
])->with([
    'JSON' => ['application/json'],
    'multipart' => ['multipart/form-data'],
]);

it('composes request examples from feature-owned contributors', function (): void {
    $examples = app(RequestExampleRegistry::class)->examples();

    expect($examples)
        ->toHaveKeys([
            'post auth/login',
            'post auth/register',
            'put account-settings',
            'put notification-settings',
            'post notification-destinations/push',
            'put notification-destinations/push/{installation}',
            'post events/{event}/registrations',
            'post membership-applications/{subjectType}/{subject}',
            'post institution-workspace/{institutionId}/members',
            'put institution-workspace/{institutionId}/members/{memberId}',
            'post saved-searches',
            'put saved-searches/{savedSearch}',
            'post reports',
            'post mobile/telemetry/events',
            'post github-issues',
        ])
        ->and($examples['post reports']['category'])->toBe('wrong_info')
        ->and($examples['post institution-workspace/{institutionId}/members']['role_id'])->toBe('institution_admin')
        ->and($examples['post mobile/telemetry/events']['events'])->toHaveCount(2)
        ->and($examples['post mobile/telemetry/events']['events'][0]['event_name'])->toBe('screen.viewed');
});

it('leaves unrelated operation request bodies unchanged', function (): void {
    $body = RequestBodyObject::make()->setContent('application/json', Schema::fromType(new ObjectType));
    $operation = Operation::make('post')->addRequestBodyObject($body);
    $operation->path = 'api/v1/unrelated-operation';
    $route = new Route('POST', 'api/v1/unrelated-operation', static fn (): null => null);
    $config = new GeneratorConfig;
    $infer = app(Infer::class);
    $extension = new ApiRequestBodyExamplesExtension($infer, new TypeTransformer($infer, new OpenApiContext(new OpenApi, $config)), $config, app(RequestExampleRegistry::class));
    $original = $body->serializeAs31();

    $extension->handle($operation, new RouteInfo($route, 'POST'));

    expect($operation->requestBodyObject)->toBe($body)
        ->and($body->serializeAs31())->toBe($original);
});
