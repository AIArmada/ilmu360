<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Documentation;

use App\Http\Controllers\Controller;
use App\Support\ApiDocumentation\ApiDocumentationUrlResolver;
use Illuminate\Http\JsonResponse;

class DocsIndexController extends Controller
{
    public function __invoke(ApiDocumentationUrlResolver $urlResolver): JsonResponse
    {
        $sections = [];

        foreach (config('scramble.documentation_sections', []) as $key => $section) {
            $sections[] = [
                'key' => $key,
                'title' => $section['title'],
                'description' => $section['description'],
                'url' => $urlResolver->docsSectionUrl((string) $key),
            ];
        }

        $document = [
            'openapi' => '3.1.0',
            'title' => 'ilmu360° API documentation index',
            'description' => 'Discover focused OpenAPI contracts or load the complete API specification.',
            'self' => $urlResolver->docsIndexUrl(),
            'complete_spec_url' => $urlResolver->docsJsonUrl(),
            'human_docs_url' => $urlResolver->docsUrl(),
            'sections' => $sections,
        ];

        $response = response()->json($document, options: JSON_PRETTY_PRINT);
        $response->setPublic();
        $response->setMaxAge(3600);
        $response->setSharedMaxAge(86400);
        $response->headers->set('Vary', 'Accept, Host');
        $response->setEtag(sha1((string) json_encode($document)));

        return $response;
    }
}
