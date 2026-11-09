<?php

use App\Features\Reports\Actions\ResolveReportCategoryOptionsAction;
use App\Features\Reports\Actions\ResolveReportEntityMetadataAction;
use App\Features\Reports\Actions\ResolveReportFormContextAction;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Shared\Reports\ReportSubjectResolver;

it('resolves shared report category options for public and admin report surfaces', function () {
    $categoryOptionsAction = app(ResolveReportCategoryOptionsAction::class);

    expect($categoryOptionsAction->handle('institution'))->toHaveKey('fake_institution', __('Fake institution'))
        ->and($categoryOptionsAction->handle('reference'))->toHaveKey('fake_reference', __('Fake reference'))
        ->and($categoryOptionsAction->handle('donation_channel'))->toHaveKey('donation_scam', __('Donation channel scam'))
        ->and($categoryOptionsAction->handle())->toHaveKey('cancelled_not_updated', __('Cancelled but not updated'))
        ->and($categoryOptionsAction->validKeys())->toContain('fake_reference', 'donation_scam');
});

it('resolves shared report entity metadata for api and admin report surfaces', function () {
    $entityMetadataAction = app(ResolveReportEntityMetadataAction::class);

    expect($entityMetadataAction->handle('reference'))->toMatchArray([
        'label' => __('Reference'),
    ])
        ->and($entityMetadataAction->options())->toHaveKey('donation_channel', __('Donation Channel'))
        ->and($entityMetadataAction->validKeys())->toContain('reference', 'donation_channel');
});

it('resolves report form context for public subjects through the action layer', function () {
    $institution = Institution::factory()->create();
    $event = Event::factory()->create();
    $person = Person::factory()->create([
        'name' => 'Amina binti Rashid',
    ]);

    $reportSubjects = app(ReportSubjectResolver::class);

    $institutionContext = app(ResolveReportFormContextAction::class)->handle(
        $reportSubjects->find('institution', (string) $institution->getKey()),
    );
    $eventContext = app(ResolveReportFormContextAction::class)->handle(
        $reportSubjects->find('event', (string) $event->getKey()),
    );
    $personContext = app(ResolveReportFormContextAction::class)->handle(
        $reportSubjects->find('person', (string) $person->getKey()),
    );

    expect($institutionContext['subject_label'])->toBe(__('Institution'))
        ->and($institutionContext['subject_title'])->toBe($institution->name)
        ->and($institutionContext['category_options'])->toHaveKey('fake_institution', __('Fake institution'))
        ->and($institutionContext['redirect_url'])->toBe(route('institutions.show', $institution))
        ->and($personContext['subject_title'])->toBe($person->formatted_name)
        ->and($eventContext['default_category'])->toBe('wrong_info')
        ->and($eventContext['subject_title'])->toBe($event->title)
        ->and($eventContext['redirect_url'])->toBe(route('events.show', $event));
});

it('resolves report subjects through the shared contract', function () {
    $event = Event::factory()->create([
        'title' => 'Majlis Ilmu Perdana',
    ]);

    $subject = app(ReportSubjectResolver::class)->find('event', (string) $event->getKey());

    expect($subject)->not->toBeNull()
        ->and($subject?->title)->toBe($event->title)
        ->and($subject?->publicUrl)->toBe(route('events.show', $event))
        ->and($subject?->slug)->toBe($event->slug)
        ->and(app(ReportSubjectResolver::class)->exists('event', (string) $event->getKey()))->toBeTrue();
});
