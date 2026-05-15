<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Controllers\Api\Documentation\DocsJsonController;
use App\Http\Controllers\Api\Documentation\DocsUiController;
use App\Support\ApiDocumentation\ApiDocumentationUrlResolver;
use App\Support\ApiDocumentation\ApiExceptionToResponseExtension;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ApiDocumentationServiceProvider extends ServiceProvider
{
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
                    Route::get('docs.json', DocsJsonController::class)->name('scramble.docs.document');
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
