<?php

declare(strict_types=1);

namespace App\Providers;

use App\Features\Contributions\EntryPoints\ApiDocumentation\ContributionsSchemasContributor;
use App\Features\Events\EntryPoints\ApiDocumentation\EventsSchemasContributor;
use App\Features\GitHubIssues\EntryPoints\ApiDocumentation\GitHubIssuesSchemasContributor;
use App\Features\Identity\EntryPoints\ApiDocumentation\IdentitySchemasContributor;
use App\Features\Institutions\EntryPoints\ApiDocumentation\InstitutionsSchemasContributor;
use App\Features\Membership\EntryPoints\ApiDocumentation\MembershipSchemasContributor;
use App\Features\Persons\EntryPoints\ApiDocumentation\PersonsSchemasContributor;
use App\Features\References\EntryPoints\ApiDocumentation\ReferencesSchemasContributor;
use App\Features\Reports\EntryPoints\ApiDocumentation\ReportsSchemasContributor;
use App\Platform\ApiDocumentation\ApiDocumentationUrlResolver;
use App\Platform\ApiDocumentation\ApiExceptionToResponseExtension;
use App\Platform\ApiDocumentation\Contributors\GlobalDocumentMetadataContributor;
use App\Platform\ApiDocumentation\Contributors\SharedSchemasContributor;
use App\Platform\ApiDocumentation\DocumentSchemaContributorRegistry;
use App\Platform\ApiDocumentation\EntryPoints\Http\DocsIndexController;
use App\Platform\ApiDocumentation\EntryPoints\Http\DocsJsonController;
use App\Platform\ApiDocumentation\EntryPoints\Http\DocsSectionController;
use App\Platform\ApiDocumentation\EntryPoints\Http\DocsUiController;
use App\Platform\Telemetry\EntryPoints\ApiDocumentation\MobileTelemetrySchemasContributor;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ApiDocumentationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DocumentSchemaContributorRegistry::class);

        // This provider is the composition root for the OpenAPI documentation
        // surface: Platform owns the neutral registry and feature owners supply
        // their document schemas and operation patches here.
        $registry = $this->app->make(DocumentSchemaContributorRegistry::class);

        $registry->register($this->app->make(SharedSchemasContributor::class));
        $registry->register($this->app->make(InstitutionsSchemasContributor::class));
        $registry->register($this->app->make(PersonsSchemasContributor::class));
        $registry->register($this->app->make(ReferencesSchemasContributor::class));
        $registry->register($this->app->make(EventsSchemasContributor::class));
        $registry->register($this->app->make(IdentitySchemasContributor::class));
        $registry->register($this->app->make(ContributionsSchemasContributor::class));
        $registry->register($this->app->make(ReportsSchemasContributor::class));
        $registry->register($this->app->make(GitHubIssuesSchemasContributor::class));
        $registry->register($this->app->make(MembershipSchemasContributor::class));
        $registry->register($this->app->make(MobileTelemetrySchemasContributor::class));
        $registry->register($this->app->make(GlobalDocumentMetadataContributor::class));
    }

    public function boot(): void
    {
        Scramble::ignoreDefaultRoutes();
        Scramble::registerExtension(ApiExceptionToResponseExtension::class);

        $apiDomain = app(ApiDocumentationUrlResolver::class)->apiDomain();
        $docsMiddleware = config('scramble.middleware', []);

        $this->app->booted(function () use ($apiDomain, $docsMiddleware): void {
            $registerDocsRoutes = function () use ($docsMiddleware): void {
                Route::middleware($docsMiddleware)->group(function (): void {
                    Route::get('docs', DocsUiController::class)->name('scramble.docs.ui');
                    Route::get('docs/index.json', DocsIndexController::class)->name('scramble.docs.index');
                    Route::get('docs.json', DocsJsonController::class)->name('scramble.docs.document');
                    Route::get('docs/{sectionKey}.json', DocsSectionController::class)
                        ->where('sectionKey', '[a-z0-9-]+')
                        ->name('scramble.docs.section');
                });
            };

            if (filled($apiDomain)) {
                Route::domain($apiDomain)->group($registerDocsRoutes);

                return;
            }

            $registerDocsRoutes();
        });
    }
}
