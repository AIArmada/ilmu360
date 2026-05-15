<?php

declare(strict_types=1);

use App\Support\ApiDocumentation\ApiDocumentationUrlResolver;
use Illuminate\Http\Request;

it('uses the configured api domain for docs and base urls', function () {
    config()->set('scramble.api_domain', 'api.ilmu360.com');
    config()->set('scramble.api_path', 'api/v1');
    config()->set('app.url', 'https://admin.ilmu360.com');

    $resolver = app(ApiDocumentationUrlResolver::class);

    expect($resolver->apiDomain())->toBe('api.ilmu360.com')
        ->and($resolver->docsUrl())->toBe('https://api.ilmu360.com/docs')
        ->and($resolver->apiBaseUrl())->toBe('https://api.ilmu360.com/api/v1');
});

it('falls back to the current app origin when running on localhost without a configured api domain', function () {
    config()->set('scramble.api_domain');
    config()->set('scramble.api_path', 'api/v1');
    config()->set('app.url', 'http://localhost');
    app()->instance('request', Request::create('http://localhost/docs', 'GET'));

    $resolver = app(ApiDocumentationUrlResolver::class);

    expect($resolver->apiDomain())->toBeNull()
        ->and($resolver->docsUrl())->toBe('http://localhost/docs')
        ->and($resolver->apiBaseUrl())->toBe('http://localhost/api/v1');
});
