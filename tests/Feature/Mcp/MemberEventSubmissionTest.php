<?php

declare(strict_types=1);

use AIArmada\Events\Models\EventOccurrence;
use App\Features\Events\Models\EventSubmission;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Features\Prayer\Api\EventPrayerTime;
use App\Features\Prayer\Persistence\PrayerTimesCache;
use App\Models\User;
use App\Platform\Mcp\McpTokenManager;
use App\Shared\Captcha\CaptchaVerifier;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Illuminate\Support\Facades\Storage;

it('resolves event options, previews an event with no writes, and submits it through member MCP', function (): void {
    $this->seed(EventRoleSeeder::class);
    fakePrayerTimesApi();
    Storage::fake('public');

    $countryId = testMalaysiaCountryId();
    $categoryId = eventCategoryId('kuliah_ceramah');
    $languageId = languageId('ms');
    $institution = Institution::factory()->create([
        'name' => 'Masjid MCP Workflow',
        'status' => 'verified',
        'allow_public_event_submission' => true,
    ]);
    $speaker = Person::factory()->create([
        'name' => 'MCP Workflow Speaker',
        'status' => 'verified',
        'allow_public_event_submission' => true,
    ]);
    syncPrimaryAddressForTest($institution, ['country_id' => $countryId]);

    $member = User::factory()->create();
    addTestMember($institution, $member, 'admin');
    $this->withToken($member->createToken('mcp-event-submit-test', [McpTokenManager::MEMBER_ABILITY])->plainTextToken);

    $this->postJson('/mcp/member', [
        'jsonrpc' => '2.0',
        'id' => 'guide',
        'method' => 'resources/read',
        'params' => ['uri' => 'file://docs/ilmu360_mcp_member_agent_guide.md'],
    ])->assertOk();

    $call = fn (string $name, array $arguments = []) => $this->postJson('/mcp/member', [
        'jsonrpc' => '2.0',
        'id' => $name,
        'method' => 'tools/call',
        'params' => ['name' => $name, 'arguments' => (object) $arguments],
    ])->assertOk();

    $call('member-find-event-institutions', ['query' => 'Masjid MCP Workflow'])
        ->assertJsonPath('result.structuredContent.data.results.0.id', $institution->id);
    $call('member-find-event-speakers', ['query' => 'MCP Workflow Speaker'])
        ->assertJsonPath('result.structuredContent.data.results.0.id', $speaker->id);

    $options = $call('member-get-event-submission-options')->json('result.structuredContent.data');
    expect(array_column($options['countries'], 'id'))->toContain($countryId)
        ->and(array_column($options['categories'], 'id'))->toContain($categoryId)
        ->and(array_column($options['languages'], 'id'))->toContain($languageId);

    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-10', ['2026-10-15' => prayerCacheDto()], 'MY');
    $call('member-preview-event-prayer-time', ['date' => '2026-10-15', 'zone' => 'WLY01'])
        ->assertJsonPath('result.structuredContent.data.starts.selepas_maghrib', '19:07');
    $call('member-preview-event-prayer-time', ['date' => '2026-02-31'])
        ->assertJsonPath('result.isError', true)
        ->assertJsonStructure(['result' => ['structuredContent' => ['error' => ['details' => ['errors' => ['date']]]]]]);

    $draft = [
        'title' => 'Member MCP submission',
        'event_category_ids' => [$categoryId],
        'languages' => [$languageId],
        'submission_country_id' => $countryId,
        'event_date' => now()->addDays(5)->toDateString(),
        'prayer_time' => EventPrayerTime::LainWaktu->value,
        'custom_time' => '10:00',
        'end_time' => '11:00',
        'primary_organizer_id' => $institution->id,
        'persons' => [$speaker->id],
    ];

    $call('member-validate-event-submission', array_replace($draft, ['end_time' => '09:00']))
        ->assertJsonPath('result.isError', true)
        ->assertJsonStructure(['result' => ['structuredContent' => ['error' => ['details' => ['errors' => ['end_time']]]]]]);

    $otherInstitution = Institution::factory()->create(['status' => 'verified']);
    $call('member-validate-event-submission', $draft + ['scoped_institution_id' => $otherInstitution->id])
        ->assertJsonPath('result.isError', true)
        ->assertJsonStructure(['result' => ['structuredContent' => ['error' => ['details' => ['errors' => ['scoped_institution_id']]]]]]);

    $captcha = Mockery::mock(CaptchaVerifier::class);
    $captcha->shouldReceive('verify')->once()->with(null, Mockery::any())->andReturnFalse();
    $captcha->shouldReceive('verify')->once()->with('verified-token', Mockery::any())->andReturnTrue();
    $this->app->instance(CaptchaVerifier::class, $captcha);

    $preview = $call('member-validate-event-submission', $draft)
        ->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.structuredContent.data.timezone', 'Asia/Kuala_Lumpur')
        ->json('result.structuredContent.data');

    expect(EventSubmission::query()->exists())->toBeFalse();

    $call('member-submit-event', $preview['draft'])
        ->assertJsonPath('result.isError', true)
        ->assertJsonStructure(['result' => ['structuredContent' => ['error' => ['details' => ['errors' => ['captcha_token']]]]]]);
    expect(EventSubmission::query()->exists())->toBeFalse();

    $cover = fakeGeneratedImageUpload('cover.jpg', 640, 360);
    $call('member-submit-event', $preview['draft'] + [
        'captcha_token' => 'verified-token',
        'cover' => ['filename' => 'cover.jpg', 'content_base64' => base64_encode((string) file_get_contents($cover->getRealPath()))],
    ])->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.structuredContent.data.auto_approved', false)
        ->assertJsonPath('result.structuredContent.data.starts_at', fn (string $value): bool => strtotime($value) === strtotime($preview['starts_at']))
        ->assertJsonPath('result.structuredContent.data.occurrence_id', fn (string $id): bool => EventOccurrence::query()->findOrFail($id)->hasMedia('cover'))
        ->assertJsonPath('result.structuredContent.data.submission_id', fn (string $id): bool => EventSubmission::query()->whereKey($id)->exists());
});
