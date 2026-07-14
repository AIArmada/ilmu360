<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Documentation;

use App\Http\Controllers\Controller;
use App\Support\ApiDocumentation\ApiDocumentationDocumentResolver;
use App\Support\ApiDocumentation\ApiDocumentationUrlResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class DocsSectionController extends Controller
{
    public function __invoke(
        string $sectionKey,
        ApiDocumentationDocumentResolver $documentResolver,
        ApiDocumentationUrlResolver $urlResolver,
    ): JsonResponse {
        $section = config("scramble.documentation_sections.{$sectionKey}");

        abort_unless(is_array($section), 404);

        $document = $documentResolver->resolve();
        $paths = [];

        foreach (($document['paths'] ?? []) as $path => $pathItem) {
            if (! is_string($path) || ! is_array($pathItem) || ! $this->matchesSection($path, $section)) {
                continue;
            }

            $paths[$path] = $pathItem;
        }

        $sectionDocument = $document;
        $sectionDocument['info'] = array_merge(
            is_array($document['info'] ?? null) ? $document['info'] : [],
            ['title' => 'ilmu360° '.(string) $section['title'].' API'],
        );
        $sectionDocument['paths'] = $paths;
        $sectionDocument['x-ilmu360-section'] = $sectionKey;
        $sectionDocument['x-ilmu360-complete-spec'] = $urlResolver->docsJsonUrl();

        $response = response()->json($sectionDocument, options: JSON_PRETTY_PRINT);
        $response->setPublic();
        $response->setMaxAge(300);
        $response->setSharedMaxAge(3600);
        $response->headers->addCacheControlDirective('stale-while-revalidate', '86400');
        $response->headers->set('Vary', 'Accept, Host');
        $response->setEtag(sha1((string) json_encode($sectionDocument)));

        return $response;
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function matchesSection(string $path, array $section): bool
    {
        foreach ($section['prefixes'] ?? [] as $prefix) {
            if (! is_string($prefix)) {
                continue;
            }

            if ($path === $prefix || Str::startsWith($path, rtrim($prefix, '/').'/')) {
                return true;
            }
        }

        return false;
    }
}
