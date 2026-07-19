<?php

use AIArmada\Events\Models\EventTemplate;
use AIArmada\FilamentEvents\Resources\EventResource;
use AIArmada\FilamentEvents\Resources\EventTemplateResource;
use AIArmada\FilamentEvents\Resources\EventTemplateResource\Pages\CreateEventTemplate;
use AIArmada\FilamentEvents\Resources\VenueResource;
use App\Filament\Resources\AiModelPricings\AiModelPricingResource;
use App\Filament\Resources\AiUsageLogs\AiUsageLogResource;
use App\Filament\Resources\ContributionRequests\ContributionRequestResource;
use App\Filament\Resources\DonationChannels\DonationChannelResource;
use App\Filament\Resources\Inspirations\InspirationResource;
use App\Filament\Resources\Institutions\InstitutionResource;
use App\Filament\Resources\Institutions\RelationManagers\DonationChannelsRelationManager as InstitutionDonationChannelsRelationManager;
use App\Filament\Resources\Institutions\RelationManagers\MemberInvitationsRelationManager as InstitutionMemberInvitationsRelationManager;
use App\Filament\Resources\MembershipApplications\MembershipApplicationResource;
use App\Filament\Resources\References\ReferenceResource;
use App\Filament\Resources\References\RelationManagers\EventsRelationManager as ReferenceEventsRelationManager;
use App\Filament\Resources\References\RelationManagers\MemberInvitationsRelationManager as ReferenceMemberInvitationsRelationManager;
use App\Filament\Resources\References\RelationManagers\MembersRelationManager as ReferenceMembersRelationManager;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\Series\RelationManagers\EventsRelationManager as SeriesEventsRelationManager;
use App\Filament\Resources\Series\SeriesResource;
use App\Filament\Resources\Spaces\SpaceResource;
use App\Filament\Resources\Speakers\RelationManagers\EventsRelationManager as SpeakerEventsRelationManager;
use App\Filament\Resources\Speakers\RelationManagers\FollowersRelationManager as SpeakerFollowersRelationManager;
use App\Filament\Resources\Speakers\RelationManagers\MemberInvitationsRelationManager as SpeakerMemberInvitationsRelationManager;
use App\Filament\Resources\Speakers\SpeakerResource;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

it('allows super admin to access all core admin resource index pages', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $resources = [
        EventResource::class,
        EventTemplateResource::class,
        InstitutionResource::class,
        SpeakerResource::class,
        VenueResource::class,
        SeriesResource::class,
        ReferenceResource::class,
        DonationChannelResource::class,
        ContributionRequestResource::class,
        MembershipApplicationResource::class,
        ReportResource::class,
        SpaceResource::class,
        AiUsageLogResource::class,
        AiModelPricingResource::class,
        InspirationResource::class,
    ];

    foreach ($resources as $resource) {
        $this->actingAs($administrator)
            ->get($resource::getUrl('index'))
            ->assertSuccessful();
    }
});

it('registers and can create commerce event templates in both panels', function () {
    expect(Filament::getPanel('admin')->getResources())->toContain(EventTemplateResource::class)
        ->and(Filament::getPanel('ahli')->getResources())->toContain(EventTemplateResource::class)
        ->and(config('filament-events.resources.enabled.event_template'))->toBeTrue()
        ->and(EventTemplateResource::getPages())->toHaveKeys(['index', 'create', 'view', 'edit']);

    $administrator = User::factory()->create();

    Livewire::actingAs($administrator)
        ->test(CreateEventTemplate::class)
        ->fillForm([
            'name' => 'Weekly Study Template',
            'code' => 'weekly-study',
            'template_type' => 'event',
            'status' => 'draft',
            'visibility' => 'private',
        ])
        ->call('create')
        ->assertHasNoErrors();

    expect(EventTemplate::query()->where('code', 'weekly-study')->value('payload'))->toBe([]);
});

it('registers expected relation managers on core admin resources', function () {
    expect(SeriesResource::getRelations())->toContain(SeriesEventsRelationManager::class);
    expect(SpeakerResource::getRelations())->toContain(SpeakerEventsRelationManager::class);
    expect(SpeakerResource::getRelations())->toContain(SpeakerFollowersRelationManager::class);
    expect(SpeakerResource::getRelations())->toContain(SpeakerMemberInvitationsRelationManager::class);
    expect(ReferenceResource::getRelations())->toContain(ReferenceEventsRelationManager::class);
    expect(ReferenceResource::getRelations())->toContain(ReferenceMembersRelationManager::class);
    expect(ReferenceResource::getRelations())->toContain(ReferenceMemberInvitationsRelationManager::class);
    expect(InstitutionResource::getRelations())->toContain(InstitutionDonationChannelsRelationManager::class);
    expect(InstitutionResource::getRelations())->toContain(InstitutionMemberInvitationsRelationManager::class);
});
