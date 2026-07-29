<?php

use AIArmada\CommerceSupport\Models\Role;
use AIArmada\Events\Enums\ScheduleKind;
use App\Actions\Events\SyncEventScheduleAction;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventVisibility;
use App\Enums\ReferenceType;
use App\Mcp\Prompts\Concerns\BuildsEventImagePrompt;
use App\Mcp\Servers\AdminServer;
use App\Mcp\Servers\MemberServer;
use App\Mcp\Tools\Admin\AdminCreateEventTool;
use App\Mcp\Tools\Admin\AdminGetRecordActionsTool;
use App\Mcp\Tools\Admin\AdminUploadEventCoverImageTool;
use App\Mcp\Tools\Member\MemberGetRecordActionsTool;
use App\Mcp\Tools\Member\MemberUploadEventCoverImageTool;
use App\Mcp\Tools\Member\MemberUploadEventPosterImageTool;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Series;
use App\Models\User;
use App\Support\Mcp\EventCoverPromptBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Mcp\Response;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    config()->set('media-library.disk_name', 'public');
    Storage::fake('public');
});

it('uploads and stores an admin 16:9 event cover image via base64 descriptor', function (): void {
    $admin = eventImageGenerationAdminUser();
    [$event] = eventImageGenerationEventFixture();

    $imageFixture = fakeGeneratedImageUpload('generated-cover.jpg', 1200, 800);
    $contents = file_get_contents($imageFixture->getRealPath());
    expect($contents)->toBeString();

    AdminServer::actingAs($admin)
        ->tool(AdminUploadEventCoverImageTool::class, [
            'event_key' => $event->slug,
            'image' => [
                'filename' => 'generated-cover.jpg',
                'content_base64' => base64_encode((string) $contents),
                'mime_type' => 'image/jpeg',
            ],
            'creative_direction' => 'Use deep emerald, warm gold.',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('event.route_key', $event->getRouteKey())
            ->where('event.slug', $event->slug)
            ->where('collection', 'cover')
            ->has('media.id')
            ->has('media.url')
            ->etc());

    $coverMedia = $event->fresh()->getFirstMedia('cover');

    expect($coverMedia)->toBeInstanceOf(Media::class)
        ->and((string) $coverMedia->collection_name)->toBe('cover');

    AdminServer::actingAs($admin)
        ->tool(AdminGetRecordActionsTool::class, [
            'resource_key' => 'events',
            'record_key' => $event->getRouteKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.focus_actions.actions', fn (mixed $actions): bool => data_get(
                collect($actions)->firstWhere('key', 'generate_event_cover_image'),
                'tool',
            ) === 'admin-upload-event-cover-image'
                && data_get(
                    collect($actions)->firstWhere('key', 'generate_event_poster_image'),
                    'tool',
                ) === 'admin-upload-event-poster-image')
            ->etc());
});

it('uploads and stores a member 3:4 event poster only for accessible events', function (): void {
    [$member, $institution] = eventImageGenerationMemberContext();
    [$event] = eventImageGenerationEventFixture($institution);

    $imageFixture = fakeGeneratedImageUpload('generated-poster.jpg', 800, 1000);
    $contents = file_get_contents($imageFixture->getRealPath());
    expect($contents)->toBeString();

    MemberServer::actingAs($member)
        ->tool(MemberUploadEventPosterImageTool::class, [
            'event_key' => $event->slug,
            'image' => [
                'filename' => 'generated-poster.jpg',
                'content_base64' => base64_encode((string) $contents),
                'mime_type' => 'image/jpeg',
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('event.route_key', $event->getRouteKey())
            ->where('collection', 'poster')
            ->has('media.id')
            ->etc());

    $posterMedia = $event->fresh()->getFirstMedia('poster');

    expect($posterMedia)->toBeInstanceOf(Media::class)
        ->and((string) $posterMedia->collection_name)->toBe('poster');

    MemberServer::actingAs($member)
        ->tool(MemberGetRecordActionsTool::class, [
            'resource_key' => 'events',
            'record_key' => $event->getRouteKey(),
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.focus_actions.actions', fn (mixed $actions): bool => data_get(
                collect($actions)->firstWhere('key', 'generate_event_cover_image'),
                'tool',
            ) === 'member-upload-event-cover-image'
                && data_get(
                    collect($actions)->firstWhere('key', 'generate_event_poster_image'),
                    'tool',
                ) === 'member-upload-event-poster-image')
            ->etc());

    $inaccessibleEvent = Event::factory()->create([
        'title' => 'Unrelated Member Event',
        'slug' => 'unrelated-member-event',
        'institution_id' => Institution::factory()->create()->getKey(),
        'status' => 'approved',
    ]);

    MemberServer::actingAs($member)
        ->tool(MemberUploadEventPosterImageTool::class, [
            'event_key' => $inaccessibleEvent->slug,
            'image' => ['filename' => 'x.jpg', 'content_base64' => 'dGVzdA==', 'mime_type' => 'image/jpeg'],
        ])
        ->assertSee('Resource not found.');
});

it('accepts a JSON-encoded string image descriptor (ChatGPT openai/fileParams serialization)', function (): void {
    $admin = eventImageGenerationAdminUser();
    [$event] = eventImageGenerationEventFixture();

    $imageFixture = fakeGeneratedImageUpload('chatgpt-cover.jpg', 1200, 800);
    $contents = file_get_contents($imageFixture->getRealPath());
    expect($contents)->toBeString();

    $descriptorString = json_encode([
        'filename' => 'chatgpt-cover.jpg',
        'content_base64' => base64_encode((string) $contents),
        'mime_type' => 'image/jpeg',
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminUploadEventCoverImageTool::class, [
            'event_key' => $event->slug,
            'image' => $descriptorString,
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('event.route_key', $event->getRouteKey())
            ->where('collection', 'cover')
            ->has('media.id')
            ->etc());

    expect($event->fresh()->getFirstMedia('cover'))->toBeInstanceOf(Media::class);
});

it('uploads and stores an admin event cover via content_url descriptor', function (): void {
    $admin = eventImageGenerationAdminUser();
    [$event] = eventImageGenerationEventFixture();

    $imageFixture = fakeGeneratedImageUpload('url-cover.jpg', 1200, 800);
    $contents = file_get_contents($imageFixture->getRealPath());
    expect($contents)->toBeString();

    Http::fake([
        'https://cdn.example.com/*' => Http::response((string) $contents, 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    AdminServer::actingAs($admin)
        ->tool(AdminUploadEventCoverImageTool::class, [
            'event_key' => $event->slug,
            'image' => [
                'filename' => 'url-cover.jpg',
                'content_url' => 'https://cdn.example.com/url-cover.jpg',
                'mime_type' => 'image/jpeg',
            ],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('event.route_key', $event->getRouteKey())
            ->where('collection', 'cover')
            ->has('media.id')
            ->etc());

    expect($event->fresh()->getFirstMedia('cover'))->toBeInstanceOf(Media::class);
});

it('rejects an invalid image descriptor (neither array nor valid JSON object)', function (): void {
    $admin = eventImageGenerationAdminUser();
    [$event] = eventImageGenerationEventFixture();

    AdminServer::actingAs($admin)
        ->tool(AdminUploadEventCoverImageTool::class, [
            'event_key' => $event->slug,
            'image' => 'not-valid-json',
        ])
        ->assertSee('The image must be a valid file descriptor object');
});

it('rejects event upload descriptors without content source', function (): void {
    $admin = eventImageGenerationAdminUser();
    [$event] = eventImageGenerationEventFixture();

    AdminServer::actingAs($admin)
        ->tool(AdminUploadEventCoverImageTool::class, [
            'event_key' => $event->slug,
            'image' => [
                'filename' => 'cover.webp',
                'mime_type' => 'image/webp',
            ],
        ])
        ->assertSee('Event image uploads require either content_base64 or content_url.');
});

it('exposes mutating and open-world metadata for event image upload tools', function (): void {
    $adminTool = app(AdminUploadEventCoverImageTool::class)->toArray();
    $memberTool = app(MemberUploadEventCoverImageTool::class)->toArray();

    expect($adminTool['annotations'] ?? [])->toMatchArray([
        'readOnlyHint' => false,
        'idempotentHint' => false,
        'destructiveHint' => true,
        'openWorldHint' => true,
    ]);

    expect($memberTool['annotations'] ?? [])->toMatchArray([
        'readOnlyHint' => false,
        'idempotentHint' => false,
        'destructiveHint' => true,
        'openWorldHint' => true,
    ]);

    expect(data_get($adminTool, '_meta.openai/toolInvocation/invoking'))->toBe('Uploading event cover image...')
        ->and(data_get($memberTool, '_meta.openai/toolInvocation/invoked'))->toBe('Event cover image uploaded.')
        ->and(data_get($adminTool, '_meta.openai/fileParams'))->toBeNull()
        ->and(data_get($memberTool, '_meta.openai/fileParams'))->toBeNull()
        ->and(data_get($adminTool, 'inputSchema.properties.image'))->toBeArray();

    expect(data_get(app(AdminCreateEventTool::class)->toArray(), '_meta.openai/fileParams'))
        ->toBeNull();
});

it('formats cover prompt text as a strict 16:9 request and exposes fallback asset links', function (): void {
    $formatter = new class
    {
        use BuildsEventImagePrompt;

        /**
         * @param  array<string, mixed>  $payload
         */
        public function format(array $payload, string $targetCollection): string
        {
            $method = new ReflectionMethod($this, 'buildPromptMessageText');

            /** @var string $text */
            $text = $method->invoke($this, $payload, $targetCollection);

            return $text;
        }
    };

    $text = $formatter->format([
        'prompt' => 'Create an editorial event visual.',
        'target' => [
            'collection' => 'poster',
            'aspect_ratio' => '3:4',
            'output_width' => 1200,
            'output_height' => 1500,
        ],
        'event' => [
            'title' => 'Tadabbur Isu Semasa',
            'route_key' => 'tadabbur-isu-semasa',
        ],
        'usage' => [
            'safety_notes' => [
                'Keep all event facts consistent with source_data.',
            ],
        ],
        'reference_media' => [
            [
                'label' => 'Event Cover Image - Tadabbur Isu Semasa',
                'role' => 'existing_event_cover',
                'collection' => 'cover',
                'selection_reason' => 'Use for continuity with the current event cover.',
                'url' => 'https://example.test/storage/events/cover.webp',
            ],
        ],
    ], 'cover');

    expect($text)
        ->toContain('Target collection: `cover`')
        ->toContain('Aspect ratio: **16:9**')
        ->toContain('strict cover request')
        ->toContain('3:4 portrait poster/flyer')
        ->toContain('fallback reference assets')
        ->toContain('https://example.test/storage/events/cover.webp');
});

it('keeps listed prompt assets aligned with the attached reference media limit', function (): void {
    [$event] = eventImageGenerationEventFixture();

    $builderResult = app(EventCoverPromptBuilder::class)->build($event, [
        'target_collection' => 'cover',
        'include_existing_media' => true,
    ]);

    expect(count($builderResult['content_media']))->toBeGreaterThan(1);

    $expectedFirstAssetUrl = (string) ($builderResult['content_media'][0]['payload']['url'] ?? '');
    $unexpectedSecondAssetUrl = (string) ($builderResult['content_media'][1]['payload']['url'] ?? '');

    $promptHarness = new class
    {
        use BuildsEventImagePrompt;

        /**
         * @param  array<string, mixed>  $arguments
         * @return array<int, Response>
         */
        public function messages(Event $event, string $targetCollection, array $arguments): array
        {
            return $this->buildEventImagePromptMessages($event, $targetCollection, $arguments);
        }
    };

    $responses = $promptHarness->messages($event, 'cover', [
        'max_reference_media' => 1,
    ]);

    $firstMessage = $responses[0]->content()->toArray();

    expect($responses)->toHaveCount(2)
        ->and($firstMessage['type'] ?? null)->toBe('text')
        ->and($firstMessage['text'] ?? '')->toContain($expectedFirstAssetUrl)
        ->and($unexpectedSecondAssetUrl === '' || ! str_contains((string) ($firstMessage['text'] ?? ''), $unexpectedSecondAssetUrl))->toBeTrue();
});

it('uses the configured Event media conversion name and dimensions in upload specs', function (): void {
    [$event] = eventImageGenerationEventFixture();

    $coverSpec = data_get(app(EventCoverPromptBuilder::class)->build($event, [
        'target_collection' => 'cover',
    ]), 'payload.upload_spec.conversions');
    $posterPayload = app(EventCoverPromptBuilder::class)->build($event, [
        'target_collection' => 'poster',
    ]);
    $posterSpec = data_get($posterPayload, 'payload.upload_spec.conversions');

    expect($coverSpec)->toBe(['thumb' => 'max 1920x1080 webp, sharpened'])
        ->and($posterSpec)->toBe(['poster_thumb' => 'max 1080x1440 webp'])
        ->and(data_get($posterPayload, 'payload.target.aspect_ratio'))->toBe('3:4')
        ->and(data_get($posterPayload, 'payload.target.ratio_width'))->toBe(3)
        ->and(data_get($posterPayload, 'payload.target.ratio_height'))->toBe(4)
        ->and(data_get($posterPayload, 'payload.target.output_width'))->toBe(1080)
        ->and(data_get($posterPayload, 'payload.target.output_height'))->toBe(1440);
});

it('embeds reference urls and title-driven ambience guidance in generated prompt text', function (): void {
    [$event] = eventImageGenerationEventFixture();

    $result = app(EventCoverPromptBuilder::class)->build($event, [
        'target_collection' => 'cover',
        'include_existing_media' => true,
    ]);

    $prompt = (string) data_get($result, 'payload.prompt', '');
    $firstReferenceUrl = (string) data_get($result, 'payload.reference_media.0.url', '');

    expect($firstReferenceUrl)->not->toBe('')
        ->and($prompt)->toContain('Selected reference media to attach/use:')
        ->and($prompt)->toContain('URL:')
        ->and($prompt)->toContain($firstReferenceUrl)
        ->and($prompt)->toContain('Use the title as an ambience anchor')
        ->and($prompt)->toContain((string) $event->title);
});

it('includes linked institution media fallback links when organizer is missing', function (): void {
    $institution = Institution::factory()->create([
        'name' => 'Masjid Al-Ihsan',
        'status' => 'verified',
    ]);

    $institution
        ->addMedia(fakeGeneratedImageUpload('institution-linked-cover.jpg', 1600, 900))
        ->toMediaCollection('cover');

    $event = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'title' => 'Kuliah Subuh Al-Ihsan',
        'slug' => 'kuliah-subuh-al-ihsan',
        'status' => 'approved',
    ]);

    $builderResult = app(EventCoverPromptBuilder::class)->build($event->fresh(), [
        'target_collection' => 'cover',
        'include_existing_media' => true,
    ]);

    $institutionMediaPayload = collect($builderResult['content_media'])
        ->pluck('payload')
        ->first(fn (mixed $payload): bool => is_array($payload) && data_get($payload, 'source.id') === (string) $institution->getKey());

    expect($institutionMediaPayload)->toBeArray();

    $expectedInstitutionAssetUrl = (string) (
        data_get($institutionMediaPayload, 'url')
        ?? data_get($institutionMediaPayload, 'original_url')
        ?? ''
    );

    expect($expectedInstitutionAssetUrl)->not->toBe('');

    $promptHarness = new class
    {
        use BuildsEventImagePrompt;

        /**
         * @param  array<string, mixed>  $arguments
         * @return array<int, Response>
         */
        public function messages(Event $event, string $targetCollection, array $arguments): array
        {
            return $this->buildEventImagePromptMessages($event, $targetCollection, $arguments);
        }
    };

    $responses = $promptHarness->messages($event->fresh(), 'cover', [
        'include_existing_media' => true,
        'max_reference_media' => 4,
    ]);

    $firstMessage = $responses[0]->content()->toArray();

    expect($firstMessage['type'] ?? null)->toBe('text')
        ->and($firstMessage['text'] ?? '')->toContain('fallback reference assets')
        ->and($firstMessage['text'] ?? '')->toContain($expectedInstitutionAssetUrl);
});

it('uses event timezone for poster prompt date and time context', function (): void {
    config()->set('app.timezone', 'UTC');

    [$event] = eventImageGenerationEventFixture();

    $event->forceFill(['timezone' => 'Asia/Kuala_Lumpur'])->save();
    app(SyncEventScheduleAction::class)->execute(
        event: $event,
        scheduleKind: ScheduleKind::Single,
        startsAt: Carbon::parse('2026-05-07 01:30:00', 'Asia/Kuala_Lumpur')->utc(),
        endsAt: Carbon::parse('2026-05-07 02:30:00', 'Asia/Kuala_Lumpur')->utc(),
        timezone: 'Asia/Kuala_Lumpur',
    );

    $promptHarness = new class
    {
        use BuildsEventImagePrompt;

        /**
         * @param  array<string, mixed>  $arguments
         * @return array<int, Response>
         */
        public function messages(Event $event, string $targetCollection, array $arguments): array
        {
            return $this->buildEventImagePromptMessages($event, $targetCollection, $arguments);
        }
    };

    $responses = $promptHarness->messages($event->fresh(), 'poster', []);
    $firstMessage = $responses[0]->content()->toArray();

    expect($firstMessage['type'] ?? null)->toBe('text')
        ->and($firstMessage['text'] ?? '')->toContain('Date and time:')
        ->and($firstMessage['text'] ?? '')->toContain('1:30 AM - 2:30 AM (Asia/Kuala_Lumpur)')
        ->and($firstMessage['text'] ?? '')->not->toContain('5:30 PM - 6:30 PM (Asia/Kuala_Lumpur)');
});

it('uses temporary signed urls for reference media payloads when disk supports it', function (): void {
    Storage::fake('s3');

    $event = Event::factory()->create([
        'institution_id' => Institution::factory()->create()->getKey(),
        'title' => 'S3 URL Event',
        'slug' => 's3-url-event',
        'status' => 'approved',
    ]);

    $event
        ->addMedia(fakeGeneratedImageUpload('s3-cover.jpg', 1600, 900))
        ->toMediaCollection('cover', 's3');

    $media = $event->getFirstMedia('cover');
    expect($media)->toBeInstanceOf(Media::class);

    $builder = app(EventCoverPromptBuilder::class);
    $payload = $builder->build($event, [
        'target_collection' => 'cover',
        'include_existing_media' => true,
        'max_reference_media' => 5,
    ]);

    $mediaItems = $payload['payload']['source_data']['available_media'] ?? [];
    $coverMedia = collect($mediaItems)->firstWhere('collection', 'cover');

    expect($coverMedia)->not->toBeNull()
        ->and($coverMedia['url'])->toContain('expiration=');
});

/**
 * @return array{0: Event, 1: Person, 2: Reference, 3: Institution}
 */
function eventImageGenerationEventFixture(?Institution $institution = null): array
{
    $institution ??= Institution::factory()->create([
        'name' => 'Masjid Al-Falah',
        'status' => 'verified',
    ]);

    $institution
        ->addMedia(fakeGeneratedImageUpload('institution-cover.jpg', 1600, 900))
        ->toMediaCollection('cover');

    $person = Person::factory()->create([
        'name' => 'Dr. MAZA',
        'status' => 'verified',
    ]);

    $person
        ->addMedia(fakeGeneratedImageUpload('person-avatar.jpg', 800, 800))
        ->toMediaCollection('avatar');

    $reference = Reference::factory()->create([
        'title' => 'Tafsir Ibn Kathir',
        'author' => 'Imam Ibn Kathir',
        'type' => ReferenceType::Book->value,
        'status' => 'verified',
    ]);

    $reference
        ->addMedia(fakeGeneratedImageUpload('reference-front-cover.jpg', 800, 1200))
        ->toMediaCollection('front_cover');

    $series = Series::factory()->create([
        'title' => 'Tadabbur Semasa',
        'status' => 'active',
    ]);

    $series
        ->addMedia(fakeGeneratedImageUpload('series-cover.jpg', 1600, 900))
        ->toMediaCollection('cover');

    $event = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'title' => 'Tadabbur: Isu Semasa Ummah',
        'slug' => 'tadabbur-isu-semasa-ummah-qdkhqqn',
        'description' => 'Kupasan tadabbur al-Quran untuk memahami isu semasa umat.',
        'starts_at' => Carbon::parse('2026-05-09 20:30:00', 'Asia/Kuala_Lumpur')->utc(),
        'ends_at' => Carbon::parse('2026-05-09 22:00:00', 'Asia/Kuala_Lumpur')->utc(),
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_category_ids' => [eventCategoryId('tazkirah')],
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'delivery_mode' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'status' => 'approved',
    ]);

    withGlobalOwnerContext(function () use ($event, $institution): void {
        $event->setPrimaryOrganizer($institution);

        expect($event->primaryOrganizerInvolvement?->involveable_type)->toBe(Institution::class)
            ->and($event->primaryOrganizerInvolvement?->involveable_id)->toBe((string) $institution->getKey());
    });

    EventKeyPerson::factory()->create([
        'event_id' => $event->getKey(),
        'involveable_type' => 'person',
        'involveable_id' => $person->getKey(),
        'role_code' => EventKeyPersonRole::Speaker->value,
        'visibility' => 'public',
        'sort_order' => 1,
    ]);

    $event->references()->attach($reference->getKey(), ['sort_order' => 1]);
    $event->series()->attach($series->getKey(), [
        'id' => (string) Str::uuid(),
        'sort_order' => 1,
    ]);

    return [$event->refresh(), $person, $reference, $institution];
}

function eventImageGenerationAdminUser(): User
{
    $role = 'super_admin';

    if (! Role::query()->where('name', $role)->where('guard_name', 'web')->exists()) {
        $roleRecord = new Role;
        $roleRecord->forceFill([
            'id' => (string) Str::uuid(),
            'name' => $role,
            'guard_name' => 'web',
        ])->save();
    }

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * @return array{0: User, 1: Institution}
 */
function eventImageGenerationMemberContext(): array
{
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $member = User::factory()->create([
        'phone' => '+60112223344',
        'phone_verified_at' => now(),
    ]);

    addTestMember($institution, $member, 'admin');

    return [$member, $institution];
}
