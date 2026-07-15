<?php

use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{domain_tag: EventTerm, discipline_tag: EventTerm, institution: Institution, speaker: Speaker}
 */
function submitEventCaptchaFixtures(): array
{
    return [
        'domain_tag' => EventTerm::factory()->create([
            'event_taxonomy_id' => EventTaxonomy::factory()->create(['code' => 'domain', 'is_active' => true])->id,
            'is_active' => true,
        ]),
        'discipline_tag' => EventTerm::factory()->create([
            'event_taxonomy_id' => EventTaxonomy::factory()->create(['code' => 'discipline', 'is_active' => true])->id,
            'is_active' => true,
        ]),
        'institution' => Institution::factory()->create(['status' => 'verified']),
        'speaker' => Speaker::factory()->create(['status' => 'verified']),
    ];
}

/**
 * @param  array{domain_tag: EventTerm, discipline_tag: EventTerm, institution: Institution, speaker: Speaker}  $fixtures
 */
function fillSubmitEventCaptchaForm(mixed $component, array $fixtures, string $title): void
{
    setSubmitEventFormState($component, [
        'title' => $title,
        'domain_tags' => [$fixtures['domain_tag']->id],
        'discipline_tags' => [$fixtures['discipline_tag']->id],
        'event_type' => [EventType::KuliahCeramah->value],
        'event_date' => now()->addDays(5)->toDateString(),
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'description' => 'Test description',
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'languages' => [101],
        'primary_organizer_id' => $fixtures['institution']->id,
        'speakers' => [$fixtures['speaker']->id],
        'submitter_name' => 'Test User',
        'submitter_email' => 'test@example.com',
    ]);
}

it('rejects submission when turnstile verification fails', function () {
    config([
        'services.turnstile.enabled' => true,
        'services.turnstile.site_key' => 'test-site-key',
        'services.turnstile.secret_key' => 'test-secret-key',
    ]);

    Http::fake([
        'api.aladhan.com/*' => Http::response([
            'code' => 200,
            'status' => 'OK',
            'data' => [
                'timings' => [
                    'Fajr' => '05:50',
                    'Dhuhr' => '13:15',
                    'Asr' => '16:40',
                    'Maghrib' => '19:25',
                    'Isha' => '20:35',
                ],
            ],
        ], 200),
        'challenges.cloudflare.com/*' => Http::response(['success' => false], 200),
    ]);

    $fixtures = submitEventCaptchaFixtures();

    $component = Livewire::test('pages.submit-event.create');
    fillSubmitEventCaptchaForm($component, $fixtures, 'Event Captcha Failed');

    $component
        ->set('data.captcha_token', 'invalid-token')
        ->call('submit')
        ->assertHasErrors(['data.captcha_token']);

    expect(Event::where('title', 'Event Captcha Failed')->exists())->toBeFalse();
});

it('accepts submission when turnstile verification succeeds', function () {
    config([
        'services.turnstile.enabled' => true,
        'services.turnstile.site_key' => 'test-site-key',
        'services.turnstile.secret_key' => 'test-secret-key',
    ]);

    Http::fake([
        'api.aladhan.com/*' => Http::response([
            'code' => 200,
            'status' => 'OK',
            'data' => [
                'timings' => [
                    'Fajr' => '05:50',
                    'Dhuhr' => '13:15',
                    'Asr' => '16:40',
                    'Maghrib' => '19:25',
                    'Isha' => '20:35',
                ],
            ],
        ], 200),
        'challenges.cloudflare.com/*' => Http::response(['success' => true], 200),
    ]);

    $fixtures = submitEventCaptchaFixtures();

    $component = Livewire::test('pages.submit-event.create');
    fillSubmitEventCaptchaForm($component, $fixtures, 'Event Captcha Passed');

    $component
        ->set('data.captcha_token', 'valid-token')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('submit-event.success'));

    expect(Event::where('title', 'Event Captcha Passed')->exists())->toBeTrue();

    Http::assertSentCount(2);
});
