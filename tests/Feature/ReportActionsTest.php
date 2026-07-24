<?php

use App\Actions\Reports\ResolveReportCategoryOptionsAction;
use App\Actions\Reports\ResolveReportEntityMetadataAction;
use App\Actions\Reports\ResolveReporterFingerprintAction;
use App\Actions\Reports\ResolveReportFormContextAction;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use Illuminate\Http\Request;

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

    $institutionContext = app(ResolveReportFormContextAction::class)->handle('institution', $institution);
    $eventContext = app(ResolveReportFormContextAction::class)->handle('event', $event);
    $speakerContext = app(ResolveReportFormContextAction::class)->handle('speaker', $person);

    expect($institutionContext['subject_label'])->toBe(__('Institution'))
        ->and($institutionContext['subject_title'])->toBe($institution->name)
        ->and($institutionContext['category_options'])->toHaveKey('fake_institution', __('Fake institution'))
        ->and($institutionContext['redirect_url'])->toBe(route('institutions.show', $institution))
        ->and($speakerContext['subject_title'])->toBe($person->formatted_name)
        ->and($eventContext['default_category'])->toBe('wrong_info')
        ->and($eventContext['subject_title'])->toBe($event->title)
        ->and($eventContext['redirect_url'])->toBe(route('events.show', $event));
});

it('resolves reporter fingerprints for authenticated and guest requests through the action layer', function () {
    $user = User::factory()->create();

    $authenticatedRequest = Request::create('/api/v1/reports', 'POST', [], [], [], [
        'REMOTE_ADDR' => '203.0.113.21',
        'HTTP_USER_AGENT' => 'ilmu360-Action-Test',
    ]);
    $authenticatedRequest->setUserResolver(fn (): User => $user);

    $guestRequest = Request::create('/api/v1/reports', 'POST', [], [], [], [
        'REMOTE_ADDR' => '203.0.113.22',
        'HTTP_USER_AGENT' => 'ilmu360-Guest-Action-Test',
    ]);
    $guestRequest->setUserResolver(fn (): null => null);

    expect(app(ResolveReporterFingerprintAction::class)->handle($authenticatedRequest))->toBe('user:'.$user->id)
        ->and(app(ResolveReporterFingerprintAction::class)->handle($guestRequest))
        ->toBe('guest:'.hash('sha256', '203.0.113.22|ilmu360-Guest-Action-Test'));
});
