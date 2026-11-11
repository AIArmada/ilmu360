<?php

declare(strict_types=1);

namespace App\Features\Events;

use App\Features\Events\Api\EventReportSubjectProvider;
use App\Features\Events\Api\GuestSubmissionClaims;
use App\Features\Events\Services\EventReportSubjectResolver;
use App\Features\Events\Support\EventModel;
use App\Platform\FeatureModule;
use App\Platform\Routing\PublicSlugRegistry;
use App\Platform\Routing\PublicSlugResource;

/**
 * Events owns event authoring, submissions, registration, and attendance.
 *
 * The public GuestSubmissionClaims boundary and report-subject provider are
 * implemented by conventional root actions until the remaining Events tables
 * are cut over.
 */
class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(
            GuestSubmissionClaims::class,
            (string) config('events.bindings.guest_submission_claims'),
        );
        $this->app->bind(EventReportSubjectProvider::class, EventReportSubjectResolver::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->app->make(PublicSlugRegistry::class)->prepend(new PublicSlugResource(
            parameter: 'event',
            routeName: 'events.show',
            modelClass: EventModel::event(),
            requiresVisitedSource: false,
            searchColumns: ['title', 'slug'],
            labelAttribute: 'title',
        ));
    }
}
