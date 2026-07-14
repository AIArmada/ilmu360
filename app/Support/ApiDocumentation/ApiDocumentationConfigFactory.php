<?php

declare(strict_types=1);

namespace App\Support\ApiDocumentation;

use Dedoc\Scramble\GeneratorConfig;
use Dedoc\Scramble\Scramble;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Str;

class ApiDocumentationConfigFactory
{
    public function __construct(
        private readonly ApiDocumentationUrlResolver $urlResolver,
    ) {}

    public function make(): GeneratorConfig
    {
        $apiPath = trim((string) config('scramble.api_path', 'api/v1'), '/');
        $config = config('scramble');
        $config['info']['description'] = $this->docsDescription(
            base_path('docs/ilmu360_mobile_api_reference.md'),
            (string) ($config['info']['description'] ?? ''),
        );

        return Scramble::configure()
            ->useConfig($config)
            ->withOperationTransformers([
                ApiSecurityRequirementExtension::class,
                ApiRequestBodyExamplesExtension::class,
            ])
            ->withDocumentTransformers([
                PublicDirectorySchemasTransformer::class,
                ApiWorkflowSchemasTransformer::class,
            ])
            ->routes(static fn (IlluminateRoute $route): bool => $apiPath === '' || Str::startsWith($route->uri(), $apiPath));
    }

    private function docsDescription(string $mobileRefPath, string $baseDescription): string
    {
        $description = trim($baseDescription);
        $description .= "\n\n---\n\n".$this->aiQuickstartMarkdown();

        if (file_exists($mobileRefPath)) {
            $mobileRefContent = file_get_contents($mobileRefPath);

            if ($mobileRefContent !== false) {
                $description .= "\n\n---\n\n".trim($mobileRefContent);
            }
        }

        return $description;
    }

    private function aiQuickstartMarkdown(): string
    {
        $apiBaseUrl = $this->urlResolver->apiBaseUrl();
        $publicManifestUrl = $apiBaseUrl.'/manifest';
        $adminManifestUrl = $apiBaseUrl.'/admin/manifest';

        return <<<MD
AI QUICKSTART:

1. Read {$this->urlResolver->docsIndexUrl()} first to discover focused OpenAPI contracts.
2. Read {$this->urlResolver->docsJsonUrl()} when you need the complete OpenAPI contract.
3. Use {$this->urlResolver->docsUrl()} for the human-readable overview and integration notes.
4. For public and client workflows, discover the live contract at {$publicManifestUrl}.
5. For admin workflows, discover writable resources at {$adminManifestUrl}.
6. Resource manifests now expose explicit `mcp_tools` for collection, meta, schema, store, and update call surfaces; use those tool names and argument templates instead of guessing URLs.
7. Event discovery supports `filter[starts_on_local_date]=YYYY-MM-DD` and returns `starts_at_local` / `starts_on_local_date` in event payloads.
8. Enum filters and write fields use enum backing values such as `kuliah_ceramah`, `all_ages`, and `prayer_relative`, not display labels such as `Kuliah / Ceramah`.
9. Before any write, fetch the exact schema first: `GET /forms/*` on the public surface, or `GET /admin/{resourceKey}/schema` on the admin surface.
10. File fields advertise `accepted_mime_types`, `max_file_size_kb`, and `max_files`; MCP write tools use JSON base64 file descriptors for advertised media/file fields instead of multipart uploads.
11. For admin record-specific schema and mutation paths, use the admin record `route_key` returned by admin collection or detail payloads. If you only have a public UUID-backed payload and `route_key` is unavailable, use the UUID `id` directly as `recordKey`.
12. Treat `error.code` as the machine-readable failure type and `meta.request_id` as the trace identifier.
MD;
    }
}
