<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Documentation;

use App\Http\Controllers\Controller;
use App\Support\ApiDocumentation\ApiDocumentationDocumentResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocsJsonController extends Controller
{
    public function __invoke(
        Request $request,
        ApiDocumentationDocumentResolver $documentResolver,
    ): JsonResponse {
        $document = $documentResolver->resolve();
        $response = response()->json($document, options: JSON_PRETTY_PRINT);

        $response->setPublic();
        $response->setMaxAge(300);
        $response->setSharedMaxAge(3600);
        $response->headers->addCacheControlDirective('stale-while-revalidate', '86400');
        $response->headers->set('Vary', 'Accept, Host');
        $response->setEtag(sha1((string) json_encode($document)));
        $response->isNotModified($request);

        return $response;
    }
}
