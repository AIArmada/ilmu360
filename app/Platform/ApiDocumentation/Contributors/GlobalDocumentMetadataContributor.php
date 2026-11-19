<?php

declare(strict_types=1);

namespace App\Platform\ApiDocumentation\Contributors;

use App\Platform\ApiDocumentation\Contracts\DocumentSchemaContributor;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Tag;

/**
 * Publishes the document-wide pieces not owned by a feature area: the bearer
 * security scheme and the descriptive copy for cross-cutting API tags.
 */
final class GlobalDocumentMetadataContributor implements DocumentSchemaContributor
{
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
        $this->registerSecurityScheme($components);
        $this->patchTagDescriptions($document);
    }

    private function registerSecurityScheme(Components $components): void
    {
        $components->addSecurityScheme(
            'sanctumBearer',
            SecurityScheme::http('bearer')
                ->as('sanctumBearer')
                ->setDescription('Sanctum bearer token returned by `POST /auth/login` or `POST /auth/register`. Send it as `Authorization: Bearer {token}`.'),
        );
    }

    private function patchTagDescriptions(OpenApi $document): void
    {
        $tagDescriptions = [
            'Catalog' => 'Public lookup catalogs for geography, tags, languages, references, venues, and write-flow selectors.',
            'Search' => 'Public aggregate search endpoints across events, persons, and institutions.',
            'Telemetry' => 'Native mobile telemetry endpoints for batching UI interaction events from real iOS, iPadOS, and Android app sessions. This surface is not for mobile web browsing.',
            'AccountSettings' => 'Authenticated account-settings read and update endpoints for client applications.',
            'GitHub Issue Reporting' => 'Authenticated feedback endpoints that create GitHub issues in the ilmu360 repository for maintainers to triage.',
            'InstitutionWorkspace' => 'Authenticated institution workspace endpoints for member management and institution-scoped event listings.',
            'MembershipApplication' => 'Authenticated membership-application endpoints for listing, creating, and cancelling applications for subject membership.',
            'EventGoing' => 'Authenticated event-going endpoints for listing, reading, creating, and deleting the current user\'s going state.',
            'EventCheckIn' => 'Authenticated event self-check-in endpoints for state discovery and check-in recording.',
            'RegistrationExport' => 'Authenticated CSV export endpoints for institution event registrations.',
            'Report' => 'Authenticated reporting endpoints for user-submitted data and content issue reports.',
        ];

        $tagsByName = [];

        foreach ($document->tags as $tag) {
            $tagsByName[$tag->name] = $tag;
        }

        foreach ($tagDescriptions as $name => $description) {
            if (array_key_exists($name, $tagsByName)) {
                $tagsByName[$name]->description = $description;

                continue;
            }

            $tagsByName[$name] = new Tag($name, $description);
        }

        $document->tags = array_values($tagsByName);
    }
}
