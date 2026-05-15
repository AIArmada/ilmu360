<?php

use App\Enums\EventVisibility;
use App\Filament\Pages\AdminDashboard;
use App\Filament\Pages\ModerationQueue;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Institutions\InstitutionResource;
use App\Filament\Resources\References\ReferenceResource;
use App\Filament\Resources\Speakers\SpeakerResource;
use App\Filament\Resources\Venues\VenueResource;
use App\Filament\Widgets\EventInventoryOverview;
use App\Filament\Widgets\StatsOverview;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
});

/**
 * @return array<int, Stat>
 */
function getDashboardStats(string $widgetClass): array
{
    $widget = app($widgetClass);
    $method = new ReflectionMethod($widget, 'getStats');

    /** @var array<int, Stat> $stats */
    $stats = $method->invoke($widget);

    return $stats;
}

it('renders the admin dashboard with moderation actions before event overview information', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $this->actingAs($administrator)
        ->get(AdminDashboard::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSeeInOrder([
            'Needs Approval',
            'Events Needing Approval',
            'Speakers Needing Approval',
            'Institutions Needing Approval',
            'References Needing Approval',
            'Venues Needing Approval',
            'Event Overview',
            'Upcoming Events',
            'Past Events',
            'Featured Events',
        ])
        ->assertSee(ModerationQueue::getUrl(panel: 'admin').'?tab=pending', false)
        ->assertSee(SpeakerResource::getUrl('index', panel: 'admin').'?tableFilters[status][value]=pending', false)
        ->assertSee(InstitutionResource::getUrl('index', panel: 'admin').'?tableFilters[status][value]=pending', false)
        ->assertSee(ReferenceResource::getUrl('index', panel: 'admin').'?tableFilters[status][value]=pending', false)
        ->assertSee(VenueResource::getUrl('index', panel: 'admin').'?tableFilters[status][value]=pending', false);
});

it('uses Dashboard as the admin dashboard label in Malay locale', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $this->actingAs($administrator)
        ->get(AdminDashboard::getUrl(panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Dashboard')
        ->assertDontSee('Papan pemuka');
});

it('renders the admin event edit page without missing dashboard navigation routes', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $event = Event::factory()->create();

    $this->actingAs($administrator)
        ->get(EditEvent::getUrl(['record' => $event->getKey()], panel: 'admin'))
        ->assertSuccessful();
});

it('computes approval and event overview dashboard stats from the intended datasets', function () {
    Event::factory()->count(2)->create([
        'status' => 'pending',
        'is_active' => false,
        'visibility' => EventVisibility::Private,
    ]);

    Speaker::factory()->create([
        'status' => 'pending',
    ]);

    Institution::factory()->create([
        'status' => 'pending',
    ]);

    Reference::factory()->create([
        'status' => 'pending',
    ]);

    Venue::factory()->create([
        'status' => 'pending',
    ]);

    Event::factory()->create([
        'status' => 'approved',
        'is_active' => true,
        'visibility' => EventVisibility::Public,
        'starts_at' => Carbon::now()->addDay(),
        'is_featured' => false,
    ]);

    Event::factory()->create([
        'status' => 'approved',
        'is_active' => true,
        'visibility' => EventVisibility::Public,
        'starts_at' => Carbon::now()->subDay(),
        'is_featured' => false,
    ]);

    Event::factory()->create([
        'status' => 'approved',
        'is_active' => true,
        'visibility' => EventVisibility::Public,
        'starts_at' => Carbon::now()->addDays(2),
        'is_featured' => true,
    ]);

    $approvalStats = getDashboardStats(StatsOverview::class);
    $overviewStats = getDashboardStats(EventInventoryOverview::class);

    expect(collect($approvalStats)->map(fn (Stat $stat): array => [
        'label' => (string) $stat->getLabel(),
        'value' => (int) $stat->getValue(),
        'url' => $stat->getUrl(),
    ])->all())->toBe([
        [
            'label' => 'Events Needing Approval',
            'value' => 2,
            'url' => ModerationQueue::getUrl(panel: 'admin').'?tab=pending',
        ],
        [
            'label' => 'Speakers Needing Approval',
            'value' => 1,
            'url' => SpeakerResource::getUrl('index', panel: 'admin').'?tableFilters[status][value]=pending',
        ],
        [
            'label' => 'Institutions Needing Approval',
            'value' => 1,
            'url' => InstitutionResource::getUrl('index', panel: 'admin').'?tableFilters[status][value]=pending',
        ],
        [
            'label' => 'References Needing Approval',
            'value' => 1,
            'url' => ReferenceResource::getUrl('index', panel: 'admin').'?tableFilters[status][value]=pending',
        ],
        [
            'label' => 'Venues Needing Approval',
            'value' => 1,
            'url' => VenueResource::getUrl('index', panel: 'admin').'?tableFilters[status][value]=pending',
        ],
    ]);

    expect(collect($overviewStats)->map(fn (Stat $stat): array => [
        'label' => (string) $stat->getLabel(),
        'value' => (int) $stat->getValue(),
    ])->all())->toBe([
        [
            'label' => 'Upcoming Events',
            'value' => 2,
        ],
        [
            'label' => 'Past Events',
            'value' => 1,
        ],
        [
            'label' => 'Featured Events',
            'value' => 1,
        ],
    ]);
});
