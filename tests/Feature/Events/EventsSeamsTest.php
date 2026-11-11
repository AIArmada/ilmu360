<?php

use AIArmada\Events\Models\EventLocation;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Features\Events\Actions\Delete\CleanupEventAction;
use App\Features\Events\Actions\Submission\ClaimGuestSubmissionsAction;
use App\Features\Events\Api\EventDeletion;
use App\Features\Events\Api\EventModeration;
use App\Features\Events\Api\EventReportSubjectProvider;
use App\Features\Events\Api\EventSubmissions;
use App\Features\Events\Api\GuestSubmissionClaims;
use App\Features\Events\Services\EventReportSubjectResolver;
use App\Features\Events\Services\EventSubmissionsService;
use App\Features\Events\Support\EventModel;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\EventSubmission;
use App\Models\Registration;
use App\Models\Series;
use App\Platform\Routing\PublicSlugRegistry;
use App\Shared\Events\Enums\EventFormat;
use App\Shared\Events\Enums\TimingMode;
use App\Shared\Reports\ReportSubjectResolver;
use App\Shared\Taxonomy\EventTaxonomyCode;
use App\Support\Events\EventModerationAdapter;
use App\Support\Reports\CompositeReportSubjectResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('binds the events seams to their interim adapters', function (): void {
    expect(app(GuestSubmissionClaims::class))->toBeInstanceOf(ClaimGuestSubmissionsAction::class)
        ->and(app(EventDeletion::class))->toBeInstanceOf(CleanupEventAction::class)
        ->and(app(EventSubmissions::class))->toBeInstanceOf(EventSubmissionsService::class)
        ->and(app(EventReportSubjectProvider::class))->toBeInstanceOf(EventReportSubjectResolver::class)
        ->and(app(ReportSubjectResolver::class))->toBeInstanceOf(CompositeReportSubjectResolver::class);
});

it('binds the events moderation boundary to its configured adapter', function (): void {
    expect(config('events.moderation.adapter'))->toBe(EventModerationAdapter::class)
        ->and(app(EventModeration::class))->toBeInstanceOf(EventModerationAdapter::class);
});

it('registers the event public slug surface from the events provider', function (): void {
    $registry = app(PublicSlugRegistry::class);
    $resource = $registry->resourceForParameter('event');

    expect($registry->parameters())->toBe(['event', 'institution', 'person', 'venue', 'reference'])
        ->and($resource?->routeName)->toBe('events.show')
        ->and($resource?->modelClass)->toBe(Event::class)
        ->and($resource?->requiresVisitedSource)->toBeFalse()
        ->and($resource?->searchColumns)->toBe(['title', 'slug'])
        ->and($resource?->labelAttribute)->toBe('title');
});

it('resolves event models through their morph aliases', function (): void {
    expect(EventModel::event())->toBe(Event::class)
        ->and(EventModel::series())->toBe(Series::class)
        ->and(EventModel::occurrence())->toBe(EventOccurrence::class)
        ->and(EventModel::session())->toBe(EventSession::class)
        ->and(EventModel::submission())->toBe(EventSubmission::class)
        ->and(EventModel::keyPerson())->toBe(EventKeyPerson::class)
        ->and(EventModel::location())->toBe(EventLocation::class)
        ->and(EventModel::registration())->toBe(Registration::class)
        ->and(fn () => EventModel::assert(new Series, 'event'))->toThrow(RuntimeException::class);
});

it('serves event report subjects through the composite resolver', function (): void {
    $event = Event::factory()->create(['title' => 'Majlis Seam']);

    $resolver = app(ReportSubjectResolver::class);
    $byId = $resolver->find('event', (string) $event->getKey());
    $bySlug = $resolver->resolve('event', (string) $event->slug);

    expect($byId?->title)->toBe('Majlis Seam')
        ->and($byId?->publicUrl)->toBe(route('events.show', $event))
        ->and($bySlug?->id)->toBe((string) $event->getKey())
        ->and($resolver->exists('event', (string) $event->getKey()))->toBeTrue()
        ->and($resolver->exists('event', 'missing-event'))->toBeFalse();
});

it('exposes the shared events vocabulary from the shared namespaces', function (): void {
    expect(EventFormat::Online->value)->toBe('online')
        ->and(TimingMode::PrayerRelative->value)->toBe('prayer_relative')
        ->and(EventTaxonomyCode::Domain->value)->toBe('domain')
        ->and(enum_exists('App\Enums\EventFormat', false))->toBeFalse();
});
