<?php

use AIArmada\FilamentAuthz\Facades\Authz;
use AIArmada\FilamentAuthz\Models\Role;
use App\Enums\ContributionSubjectType;
use App\Enums\EventStructure;
use App\Enums\EventVisibility;
use App\Filament\Ahli\Resources\Events\EventResource;
use App\Livewire\Pages\Dashboard\InstitutionDashboard;
use App\Livewire\Pages\Dashboard\UserDashboard;
use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\NotificationMessage;
use App\Models\Reference;
use App\Models\Registration;
use App\Models\SavedSearch;
use App\Models\Space;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Authz\MemberRoleScopes;
use App\Support\Authz\ScopedMemberRoleSeeder;
use Filament\Tables\Enums\PaginationMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('requires authentication for user and institution dashboards', function () {
    expect(route('dashboard'))->toEndWith('/dashboard');
    expect(route('dashboard.account-settings'))->toEndWith('/tetapan-akaun');
    expect(route('dashboard.institutions'))->toEndWith('/dashboard/institusi');
    expect(route('dashboard.institutions.events'))->toEndWith('/dashboard/institusi/senarai-majlis');
    expect(route('dashboard.institutions.submit-event'))->toEndWith('/dashboard/institusi/tambah-majlis');

    $this->get('/papan-pemuka')->assertNotFound();
    $this->get('/dashboard')->assertRedirect(route('login'));
    $this->get('/dashboard/notifications')->assertRedirect(route('login'));
    $this->get('/tetapan-akaun')->assertRedirect(route('login'));
    $this->get('/dashboard/institusi')->assertRedirect(route('login'));
    $this->get('/dashboard/institusi/senarai-majlis')->assertRedirect(route('login'));
    $this->get('/dashboard/institusi/tambah-majlis')->assertRedirect(route('login'));
    $this->get('/dashboard/institusi/hantar-majlis')->assertNotFound();
    $this->get(route('dashboard.events.create-advanced'))->assertRedirect(route('login'));
});

it('does not expose the legacy papan pemuka dashboard URL', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/papan-pemuka')
        ->assertNotFound();
});

it('renders the reference-inspired user dashboard with real saved search and notification panels', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $institution = Institution::factory()->create(['name' => 'Masjid Al-Hidayah']);
    $otherInstitution = Institution::factory()->create(['name' => 'Masjid Al-Furqan']);

    $user->institutions()->attach($institution->id);

    $savedEvent = Event::factory()->for($otherUser)->for($institution)->create([
        'title' => 'Saved Dashboard Event',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(2),
    ]);

    $goingEvent = Event::factory()->for($otherUser)->for($institution)->create([
        'title' => 'Going Dashboard Event',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(4),
    ]);

    $registeredEvent = Event::factory()->for($otherUser)->for($institution)->create([
        'title' => 'Registered Dashboard Event',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(5),
    ]);

    $submittedEvent = Event::factory()->for($user)->for($institution)->create([
        'title' => 'Submitted Dashboard Event',
        'submitter_id' => $user->id,
        'status' => 'pending',
        'visibility' => 'public',
        'starts_at' => now()->addDays(6),
    ]);

    EventSubmission::factory()->for($submittedEvent)->for($user, 'submitter')->create();

    $checkedInEvent = Event::factory()->for($otherUser)->for($institution)->create([
        'title' => 'Checked In Dashboard Event',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->subDays(1),
    ]);

    $managedOnlyEvent = Event::factory()->for($otherUser)->for($institution)->create([
        'title' => 'Institution Managed Event',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(8),
    ]);

    Event::factory()->for($otherUser)->for($otherInstitution)->create([
        'title' => 'External Event',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(9),
    ]);

    Registration::factory()->for($registeredEvent)->for($user)->create([
        'status' => 'registered',
    ]);

    $user->savedEvents()->attach($savedEvent->id);
    $user->goingEvents()->attach($goingEvent->id);

    EventCheckin::factory()->for($checkedInEvent)->for($user)->create([
        'checked_in_at' => now()->subDay(),
        'method' => 'registered_self_checkin',
    ]);

    SavedSearch::factory()->for($user)->create([
        'name' => 'Kuliah KL Daily',
    ]);

    SavedSearch::factory()->for($otherUser)->create([
        'name' => 'Other User Search',
    ]);

    NotificationMessage::factory()->for($user, 'notifiable')->create([
        'data' => [
            'title' => 'Reminder majlis akan datang',
            'body' => 'Kuliah Maghrib bermula dalam 2 jam lagi.',
        ],
        'action_url' => route('events.show', $goingEvent),
        'occurred_at' => now()->subHour(),
    ]);

    $response = $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->get('/dashboard');

    $response->assertOk()
        ->assertSee('Dashboard')
        ->assertSee('Perjalanan Menuju Allah')
        ->assertSee('Keep seeking knowledge steadily')
        ->assertSee('data-testid="dashboard-hero-shell"', false)
        ->assertSee('data-testid="dashboard-hero-media"', false)
        ->assertSee('My Events')
        ->assertSee('Latest Notifications')
        ->assertSee('Quick Actions')
        ->assertSee('Recommended for you')
        ->assertSee('Home')
        ->assertSee('Workspaces')
        ->assertSee('Account')
        ->assertSee('My Dashboard')
        ->assertSee('Inbox')
        ->assertSee('My Contributions')
        ->assertDontSee('Planner menu')
        ->assertDontSee('Overview Calendar')
        ->assertDontSee('Upcoming Agenda')
        ->assertDontSee('What needs your attention next')
        ->assertDontSee('Submitted Events')
        ->assertDontSee('Recent Check-ins')
        ->assertDontSee('Submitted + Check-ins')
        ->assertDontSee('Going + Registered')
        ->assertSee('Saved Dashboard Event')
        ->assertSee('Going Dashboard Event')
        ->assertSee('Kuliah KL Daily')
        ->assertSee('Reminder majlis akan datang')
        ->assertSee('Find nearby events')
        ->assertSee('My Contribution')
        ->assertDontSee('Checked In Dashboard Event')
        ->assertDontSee('My Saved Searches')
        ->assertDontSee('Notification Preferences');

    $html = (string) $response->getContent();
    $majlisSayaSection = str($html)->between('id="majlis-saya"', 'id="ikuti"')->toString();

    expect($majlisSayaSection)
        ->toContain('Saved Dashboard Event')
        ->toContain('Going Dashboard Event')
        ->not->toContain('Registered Dashboard Event')
        ->not->toContain('Submitted Dashboard Event');

    $response->assertDontSee('Other User Search');
    $response->assertDontSee('x-show="cell.entries.length > 0"', false);
    $response->assertDontSee("entry.role_badges.map(badge => badge.label).join(' • ')", false);
    $response->assertDontSee('bg-emerald-50/80', false);
    $response->assertDontSee('absolute inset-y-0 right-0 hidden w-1/2', false);

    expect($html)->toContain(route('dashboard.dawah-impact'));
});

it('shows the redesigned followed-entity category cards on the dashboard', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $institution = Institution::factory()->create(['name' => 'Masjid Al-Hidayah']);
    $followedInstitution = Institution::factory()->create(['name' => 'Masjid Al-Makmur']);

    $savedEvent = Event::factory()->for($otherUser)->for($institution)->create([
        'title' => 'Sidebar Saved Event',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(2),
    ]);

    $goingEvent = Event::factory()->for($otherUser)->for($institution)->create([
        'title' => 'Sidebar Going Event',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(4),
    ]);

    $speaker = Speaker::factory()->create([
        'name' => 'Menu Speaker',
    ]);

    $reference = Reference::factory()->create([
        'title' => 'Menu Reference',
        'author' => 'Menu Author',
    ]);

    $user->savedEvents()->attach($savedEvent->id);
    $user->goingEvents()->attach($goingEvent->id);
    $user->follow($speaker);
    $user->follow($reference);
    $user->follow($followedInstitution);

    $response = $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->get('/dashboard');

    $response->assertOk()
        ->assertDontSee('Planner menu')
        ->assertDontSee('planner-calendar', false)
        ->assertSee('id="ikuti"', false)
        ->assertSee('My Events')
        ->assertSee('Follow')
        ->assertSee('Institution')
        ->assertSee('Speaker')
        ->assertSee('Saved')
        ->assertSee('Going')
        ->assertSee('Sidebar Saved Event')
        ->assertSee('Sidebar Going Event');

    $this->withSession(['locale' => 'ms'])
        ->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Ikuti')
        ->assertSee('Masjid dan surau yang saya ikuti')
        ->assertSee('Kitab dan bahan bacaan yang saya ikuti')
        ->assertSee('Penceramah Diikuti')
        ->assertSee('Institusi Diikuti');
});

it('uses institution cover media only for the follow cards and renders reference/search visual stacks', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $institution = Institution::factory()->create(['name' => 'Masjid Ikuti Utama']);

    Event::factory()->for($otherUser)->for($institution)->create([
        'title' => 'Follow Cards Event',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(2),
    ]);

    $coverOnlyInstitution = Institution::factory()->create(['name' => 'Masjid Cover Sahaja']);
    $logoOnlyInstitution = Institution::factory()->create(['name' => 'Masjid Logo Sahaja']);

    $coverOnlyInstitution->addMedia(UploadedFile::fake()->image('dashboard-cover.jpg', 1600, 900))
        ->toMediaCollection('cover');

    $logoOnlyInstitution->addMedia(UploadedFile::fake()->image('dashboard-logo.png', 600, 600))
        ->toMediaCollection('logo');

    $reference = Reference::factory()->create([
        'title' => 'Rujukan Dashboard Visual',
    ]);

    $reference->addMedia(UploadedFile::fake()->image('dashboard-reference.jpg', 900, 1200))
        ->toMediaCollection('front_cover');

    SavedSearch::factory()->for($user)->create([
        'name' => 'Kuliah Subuh KL',
    ]);

    $user->follow($coverOnlyInstitution);
    $user->follow($logoOnlyInstitution);
    $user->follow($reference);

    $response = $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->get('/dashboard');

    $response->assertOk()
        ->assertSee('data-testid="dashboard-follow-institution-covers"', false)
        ->assertSee('data-testid="dashboard-follow-reference-stack"', false)
        ->assertSee('data-testid="dashboard-follow-search-preview"', false)
        ->assertSee('dashboard-cover', false)
        ->assertDontSee('dashboard-logo', false);
});

it('renders a valid event management link on the user dashboard for manageable events', function () {
    $user = User::factory()->create();

    $event = Event::factory()->for($user)->create([
        'submitter_id' => $user->id,
        'status' => 'approved',
        'visibility' => 'public',
        'is_active' => true,
        'starts_at' => now()->addDays(3),
    ]);

    $expectedManagementUrl = EventResource::getUrl('view', ['record' => $event], panel: 'ahli');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('dashboard.events.schedule')
        ->assertDontSee($expectedManagementUrl, false);
});

it('shows a featured next event without repeating it inside an otherwise empty agenda list', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create();

    $event = Event::factory()->for($institution)->create([
        'title' => 'Single Upcoming Planner Event',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(2),
    ]);

    $user->goingEvents()->attach($event->id);

    $response = $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->get('/dashboard');

    $response->assertOk()
        ->assertSee('Single Upcoming Planner Event')
        ->assertDontSee('Your next event is already featured above.')
        ->assertDontSee('No events are on your immediate agenda.');
});

it('renders the redesigned dashboard in Malay with reference-inspired sections', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create();
    $otherUser = User::factory()->create();

    $user->institutions()->attach($institution->id);

    $goingEvent = Event::factory()->for($otherUser)->for($institution)->create([
        'title' => 'Majlis Akan Hadir',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(2),
    ]);

    $submittedEvent = Event::factory()->for($user)->for($institution)->create([
        'title' => 'Majlis Dihantar Sendiri',
        'submitter_id' => $user->id,
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(3),
    ]);

    $user->goingEvents()->attach($goingEvent->id);

    EventSubmission::factory()->for($submittedEvent)->for($user, 'submitter')->create();

    $response = $this->withSession(['locale' => 'ms'])
        ->actingAs($user)
        ->get('/dashboard');

    $response->assertOk()
        ->assertSee('Dashboard')
        ->assertSee('Perjalanan Menuju Allah')
        ->assertSee('Teruskan istiqamah mencari ilmu dan sebarkan manfaatnya.')
        ->assertSee('Majlis Saya')
        ->assertSee('Notifikasi Terkini')
        ->assertSee('Tindakan Pantas')
        ->assertSee('Sumbangan Saya')
        ->assertDontSee('Perancang Kehadiran')
        ->assertSee('Dashboard')
        ->assertSee('Urus Institusi')
        ->assertSee('Majlis Akan Hadir')
        ->assertDontSee('Yang perlu anda urus selepas ini')
        ->assertDontSee('Lihat lagi')
        ->assertDontSee('Approved');

    $majlisSayaSection = str((string) $response->getContent())->between('id="majlis-saya"', 'id="ikuti"')->toString();

    expect($majlisSayaSection)
        ->toContain('Majlis Akan Hadir')
        ->not->toContain('Majlis Dihantar Sendiri');

    $html = $response->getContent();

    expect($html)->toBeString();

    $html = (string) $html;
    preg_match('/href="'.preg_quote(route('dashboard'), '/').'".*?>(.*?)<\/a>/s', $html, $dashboardMenuMatch);
    preg_match('/href="'.preg_quote(route('dashboard.institutions'), '/').'".*?>(.*?)<\/a>/s', $html, $institutionDashboardMenuMatch);

    $dashboardMenuText = trim(strip_tags($dashboardMenuMatch[1] ?? ''));
    $institutionDashboardMenuText = trim(strip_tags($institutionDashboardMenuMatch[1] ?? ''));

    expect($dashboardMenuText)->toBe('Dashboard')
        ->and($institutionDashboardMenuText)->toBe('Urus Institusi');
});

it('paginates redesigned majlis cards when counts exceed the dashboard page size', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $institution = Institution::factory()->create();

    foreach (range(1, 8) as $index) {
        $event = Event::factory()->for($otherUser)->for($institution)->create([
            'title' => 'Going Page Event '.$index,
            'status' => 'approved',
            'visibility' => 'public',
            'starts_at' => now()->addDays($index),
        ]);

        $user->goingEvents()->attach($event->id);
    }

    $response = $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->get('/dashboard?majlis_page=2');

    $response->assertOk();

    $html = (string) $response->getContent();

    $majlisSectionText = str($html)
        ->after('id="majlis-saya"')
        ->toString();

    expect($html)->toContain('Going Page Event 8');
    expect($majlisSectionText)->toContain('Going Page Event 7')
        ->toContain('Going Page Event 8')
        ->not->toContain('Going Page Event 1')
        ->not->toContain('Going Page Event 6');
    expect($html)->toContain('window.scrollTo({ top: targetTop, left: 0, behavior: &#039;auto&#039; })')
        ->not->toContain('scrollIntoView()');
});

it('does not expose the removed legacy account settings urls', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/papan-pemuka/tetapan-akaun')
        ->assertNotFound();

    $this->actingAs($user)
        ->get('/dashboard/account-settings')
        ->assertNotFound();

    $this->actingAs($user)
        ->get('/papan-pemuka/pilihan-digest')
        ->assertNotFound();

    $this->actingAs($user)
        ->get('/dashboard/digest-preferences')
        ->assertNotFound();

    $followedResponse = $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->get(route('dashboard.account-settings', ['tab' => 'notifications']));

    $followedResponse->assertOk()
        ->assertSee('Account Settings')
        ->assertSee('Manage your account and notifications from one place.')
        ->assertSee('Open inbox')
        ->assertSee('Save Notification Settings');
});

it('hides institution dashboard access for users without institution membership', function () {
    $user = User::factory()->create();

    $dashboardResponse = $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->get('/dashboard');

    $dashboardResponse->assertOk()
        ->assertDontSee('Manage Institution');

    $this->actingAs($user)
        ->get(route('dashboard.institutions'))
        ->assertForbidden();
});

it('renders the institution dashboard picker as a plain selector without search UI', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjid Flux Pilihan']);

    $institution->members()->syncWithoutDetaching([$user->id]);

    $response = $this->actingAs($user)
        ->get(route('dashboard.institutions', ['institution' => $institution->id]));

    $response->assertOk()
        ->assertSee('data-testid="institution-dashboard-picker"', false)
        ->assertSee('data-testid="institution-dashboard-select"', false)
        ->assertDontSee('data-testid="institution-dashboard-picker-option"', false)
        ->assertDontSee('Search institution...')
        ->assertSee('Masjid Flux Pilihan');
});

it('shows seven nearest majlis on the institution dashboard and links to the dedicated event list', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjid Senarai Majlis']);
    $otherInstitution = Institution::factory()->create(['name' => 'Masjid Luar']);

    $user->institutions()->attach($institution->id);

    foreach (range(1, 8) as $index) {
        Event::factory()->for($institution)->create([
            'title' => sprintf('Majlis Terdekat %02d', $index),
            'status' => 'approved',
            'visibility' => 'public',
            'starts_at' => now()->addDays($index),
        ]);
    }

    Event::factory()->for($otherInstitution)->create([
        'title' => 'Majlis Institusi Luar',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDay(),
    ]);

    $eventsUrl = route('dashboard.institutions.events', ['institution' => $institution->id]);

    $dashboardResponse = $this->withSession(['locale' => 'ms'])
        ->actingAs($user)
        ->get(route('dashboard.institutions', ['institution' => $institution->id]));

    $dashboardResponse->assertOk()
        ->assertSee('Majlis')
        ->assertSee('Lihat semua majlis')
        ->assertSee($eventsUrl, false)
        ->assertSeeInOrder([
            'Majlis Terdekat 01',
            'Majlis Terdekat 02',
            'Majlis Terdekat 03',
            'Majlis Terdekat 04',
            'Majlis Terdekat 05',
            'Majlis Terdekat 06',
            'Majlis Terdekat 07',
        ])
        ->assertDontSee('Majlis Terdekat 08')
        ->assertDontSee('Majlis Institusi Luar')
        ->assertDontSee('Program Saya')
        ->assertDontSee('Program Akan Datang')
        ->assertDontSee('Jumlah Program')
        ->assertDontSee('/js/filament/tables/tables.js', false);

    $eventsResponse = $this->withSession(['locale' => 'ms'])
        ->actingAs($user)
        ->get($eventsUrl);

    $eventsResponse->assertOk()
        ->assertSee('Senarai Majlis')
        ->assertSee('Majlis Terdekat 08')
        ->assertDontSee('Program Saya');
});

it('loads filament table assets after the core filament scripts on the institution event list page', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjid Table Assets']);

    $institution->members()->syncWithoutDetaching([$user->id]);

    $response = $this->actingAs($user)
        ->get(route('dashboard.institutions.events', ['institution' => $institution->id]));

    $response->assertOk()
        ->assertSee('/js/filament/support/support.js', false)
        ->assertSee('/js/filament/tables/tables.js', false)
        ->assertSee('data-update-uri=', false);

    $html = $response->getContent();

    expect($html)->toBeString();
    expect(strpos((string) $html, '/js/filament/support/support.js'))->toBeLessThan(strpos((string) $html, '/js/filament/tables/tables.js'));
});

it('merges overlapping planner relationships into one calendar entry', function () {
    $user = User::factory()->create();

    $event = Event::factory()->create([
        'title' => 'Merged Planner Event',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(2),
    ]);

    $user->savedEvents()->attach($event->id);
    $user->goingEvents()->attach($event->id);

    Registration::factory()->for($event)->for($user)->create([
        'status' => 'registered',
    ]);

    $component = Livewire::actingAs($user)->test(UserDashboard::class);

    /** @var UserDashboard $instance */
    $instance = $component->instance();

    expect($instance->calendarEntries)->toHaveCount(1)
        ->and($instance->calendarEntries[0]['roles'])->toBe([
            'saved',
            'going',
        ])
        ->and($instance->upcomingAgenda)->toHaveCount(1);
});

it('shows institution profile and events for members without a separate registrations section', function () {
    $user = User::factory()->create();
    $attendee = User::factory()->create();

    $institution = Institution::factory()->create(['name' => 'Masjid Al-Ikhlas']);
    $otherInstitution = Institution::factory()->create(['name' => 'Masjid Al-Istiqamah']);

    $institution->members()->syncWithoutDetaching([$user->id]);

    app(ScopedMemberRoleSeeder::class)->ensureForInstitution();

    $institutionScope = app(MemberRoleScopes::class)->institution();

    Authz::withScope($institutionScope, function () use ($user): void {
        $user->syncRoles(['admin']);
    }, $user);

    $eventInInstitution = Event::factory()->for($institution)->create([
        'title' => 'Institution Dashboard Event',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(5),
    ]);

    $speaker = Speaker::factory()->create([
        'name' => 'Ustaz Dashboard Speaker',
    ]);
    $reference = Reference::factory()->create([
        'title' => 'Kitab Dashboard Reference',
    ]);
    $space = Space::factory()->create([
        'name' => 'Dewan Utama Institusi',
    ]);

    $institution->spaces()->syncWithoutDetaching([$space->id]);
    $eventInInstitution->update(['space_id' => $space->id]);
    $eventInInstitution->speakers()->attach($speaker->id);
    $eventInInstitution->references()->attach($reference->id);

    $parentProgram = Event::factory()->for($institution)->create([
        'title' => 'Institution Parent Majlis',
        'event_structure' => EventStructure::ParentProgram->value,
        'status' => 'draft',
        'visibility' => 'public',
        'starts_at' => now()->addDays(7),
    ]);

    $eventOutsideInstitution = Event::factory()->for($otherInstitution)->create([
        'title' => 'Outside Institution Event',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(6),
    ]);

    Registration::factory()->for($eventInInstitution)->for($attendee)->create([
        'name' => 'Ahmad Registrant',
        'email' => 'ahmad@example.com',
        'status' => 'registered',
    ]);

    Registration::factory()->for($eventOutsideInstitution)->create([
        'name' => 'External Registrant',
        'status' => 'registered',
    ]);

    $response = $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->get(route('dashboard.institutions', ['institution' => $institution->id]));

    $response->assertOk()
        ->assertSee('Masjid Al-Ikhlas')
        ->assertSee('Manage Institution')
        ->assertSee('Majlis')
        ->assertSee('View all majlis')
        ->assertSee('Waiting Review')
        ->assertSee('Summary Statistics')
        ->assertSee('Add Event')
        ->assertDontSee('Create Advanced Majlis')
        ->assertSee('Members & Roles')
        ->assertSee('Institution Dashboard Event')
        ->assertDontSee('Event List')
        ->assertDontSee('Ustaz Dashboard Speaker')
        ->assertDontSee('Kitab Dashboard Reference')
        ->assertDontSee('Add Child Event')
        ->assertDontSee('Event Registrations')
        ->assertDontSee('Registrations (All)')
        ->assertDontSee('Ahmad Registrant')
        ->assertDontSee('Outside Institution Event')
        ->assertDontSee('External Registrant');

    $response->assertDontSee('/js/filament/tables/tables.js', false);

    $eventsResponse = $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->get(route('dashboard.institutions.events', ['institution' => $institution->id]));

    $eventsResponse->assertOk()
        ->assertSee('Masjid Al-Ikhlas')
        ->assertSee('Event List')
        ->assertSee('Speakers')
        ->assertSee('References')
        ->assertSee('Location')
        ->assertSee('Institution Dashboard Event')
        ->assertSee('Institution Parent Majlis')
        ->assertSee('Ustaz Dashboard Speaker')
        ->assertSee('Kitab Dashboard Reference')
        ->assertSee('Dewan Utama Institusi')
        ->assertSee('Add Child Event')
        ->assertDontSee('Event Registrations')
        ->assertDontSee('Registrations (All)')
        ->assertDontSee('Ahmad Registrant')
        ->assertDontSee('Outside Institution Event')
        ->assertDontSee('External Registrant');

    $response->assertSee(e(route('dashboard.institutions.events', ['institution' => $institution->id])), false);
    $response->assertSee(e(route('dashboard.institutions.submit-event', ['institution' => $institution->id])), false);
    $eventsResponse->assertSee(e(route('dashboard.institutions.submit-event', ['institution' => $institution->id, 'duplicate' => $eventInInstitution->id])), false);
    $eventsResponse->assertSee(e(route('submit-event.create', ['parent' => $parentProgram->id])), false);
});

it('only shows duplicate event links on the institution dashboard to users who can update the event', function () {
    $adminUser = User::factory()->create();
    $viewerUser = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjid Kebenaran Majlis']);

    $institution->members()->syncWithoutDetaching([
        $adminUser->id,
        $viewerUser->id,
    ]);

    app(ScopedMemberRoleSeeder::class)->ensureForInstitution();

    $institutionScope = app(MemberRoleScopes::class)->institution();

    Authz::withScope($institutionScope, function () use ($adminUser, $viewerUser): void {
        $adminUser->syncRoles(['admin']);
        $viewerUser->syncRoles(['viewer']);
    }, $adminUser);

    $event = Event::factory()->for($institution)->create([
        'title' => 'Permission Scoped Event',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(4),
    ]);

    $editEventUrl = e(EventResource::getUrl('edit', ['record' => $event], panel: 'ahli'));
    $duplicateEventUrl = e(route('dashboard.institutions.submit-event', ['institution' => $institution->id, 'duplicate' => $event->id]));

    $this->withSession(['locale' => 'en'])
        ->actingAs($adminUser)
        ->get(route('dashboard.institutions', ['institution' => $institution->id]))
        ->assertOk()
        ->assertSee($editEventUrl, false)
        ->assertSee($duplicateEventUrl, false);

    $this->withSession(['locale' => 'en'])
        ->actingAs($viewerUser)
        ->get(route('dashboard.institutions', ['institution' => $institution->id]))
        ->assertOk()
        ->assertDontSee($duplicateEventUrl, false);
});

it('hides scoped submit and duplicate links for inactive institution dashboards', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'name' => 'Masjid Tidak Aktif',
        'status' => 'verified',
        'is_active' => false,
        'allow_public_event_submission' => true,
    ]);

    $institution->members()->syncWithoutDetaching([$user->id]);

    app(ScopedMemberRoleSeeder::class)->ensureForInstitution();

    $institutionScope = app(MemberRoleScopes::class)->institution();

    Authz::withScope($institutionScope, function () use ($user): void {
        $user->syncRoles(['admin']);
    }, $user);

    $event = Event::factory()->for($institution)->create([
        'title' => 'Inactive Institution Event',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(4),
    ]);

    $editEventUrl = e(EventResource::getUrl('edit', ['record' => $event], panel: 'ahli'));
    $submitEventUrl = e(route('dashboard.institutions.submit-event', ['institution' => $institution->id]));
    $duplicateEventUrl = e(route('dashboard.institutions.submit-event', ['institution' => $institution->id, 'duplicate' => $event->id]));

    $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->get(route('dashboard.institutions', ['institution' => $institution->id]))
        ->assertOk()
        ->assertSee('Inactive Institution Event')
        ->assertSee($editEventUrl, false)
        ->assertDontSee($submitEventUrl, false)
        ->assertDontSee($duplicateEventUrl, false);

    $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->get(route('dashboard.institutions.submit-event', ['institution' => $institution->id, 'duplicate' => $event->id]))
        ->assertForbidden();
});

it('renders institution event list dates with translated times or prayer labels', function () {
    $user = User::factory()->create([
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);
    $institution = Institution::factory()->create(['name' => 'Masjid Format Masa']);

    $institution->members()->syncWithoutDetaching([$user->id]);

    $absoluteStartsAt = Carbon::create(2026, 4, 15, 11, 25, 0, 'UTC');
    $prayerStartsAt = Carbon::create(2026, 4, 16, 11, 55, 0, 'UTC');

    Event::factory()->for($institution)->create([
        'title' => 'Majlis Masa Biasa',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => $absoluteStartsAt,
        'timing_mode' => 'absolute',
        'prayer_reference' => null,
        'prayer_offset' => null,
        'prayer_display_text' => null,
    ]);

    Event::factory()->for($institution)->kuliahMaghrib()->create([
        'title' => 'Majlis Selepas Maghrib',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => $prayerStartsAt,
    ]);

    $expectedAbsoluteLabel = $absoluteStartsAt->copy()
        ->timezone('Asia/Kuala_Lumpur')
        ->locale('ms')
        ->translatedFormat('d M Y, h:i A');

    $expectedPrayerLabel = $prayerStartsAt->copy()
        ->timezone('Asia/Kuala_Lumpur')
        ->locale('ms')
        ->translatedFormat('d M Y').', Selepas Maghrib';

    $this->withSession(['locale' => 'ms'])
        ->actingAs($user)
        ->get(route('dashboard.institutions.events', ['institution' => $institution->id]))
        ->assertOk()
        ->assertSee($expectedAbsoluteLabel)
        ->assertSee($expectedPrayerLabel);
});

it('highlights institution events that are waiting approval', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjid Menunggu Kelulusan']);

    $user->institutions()->attach($institution->id);

    Event::factory()->for($institution)->create([
        'title' => 'Pending Institution Dashboard Event',
        'status' => 'pending',
        'visibility' => 'public',
        'starts_at' => now()->addDays(3),
    ]);

    $response = $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->get(route('dashboard.institutions', ['institution' => $institution->id]));

    $response->assertOk()
        ->assertSee('Pending Institution Dashboard Event')
        ->assertSee('Pending');
});

it('filters and sorts institution events on the dedicated event list page', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjid Tapis Majlis']);
    $otherInstitution = Institution::factory()->create();

    $user->institutions()->attach($institution->id);

    $zuluEvent = Event::factory()->for($institution)->create([
        'title' => 'Zulu Public Event',
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(3),
    ]);

    $alphaEvent = Event::factory()->for($institution)->create([
        'title' => 'Alpha Hidden Pending Event',
        'status' => 'pending',
        'visibility' => 'private',
        'starts_at' => now()->addDays(1),
    ]);

    $betaEvent = Event::factory()->for($institution)->create([
        'title' => 'Beta Unlisted Event',
        'status' => 'approved',
        'visibility' => 'unlisted',
        'starts_at' => now()->addDays(2),
    ]);

    $outsideEvent = Event::factory()->for($otherInstitution)->create([
        'title' => 'Outside Event',
        'status' => 'pending',
        'visibility' => 'private',
        'starts_at' => now()->addDays(4),
    ]);

    Registration::factory()->for($zuluEvent)->create();
    Registration::factory()->for($zuluEvent)->create();
    Registration::factory()->for($betaEvent)->create();

    $filteredResponse = $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->get(route('dashboard.institutions.events', [
            'institution' => $institution->id,
            'event_search' => 'alpha',
            'event_status' => 'pending',
            'event_visibility' => 'private',
        ]));

    $filteredResponse->assertOk()
        ->assertSee('Alpha Hidden Pending Event')
        ->assertDontSee('Zulu Public Event')
        ->assertDontSee('Beta Unlisted Event')
        ->assertDontSee('Outside Event');

    $sortedResponse = $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->get(route('dashboard.institutions.events', [
            'institution' => $institution->id,
            'event_sort' => 'title_asc',
        ]));

    $sortedResponse->assertOk()
        ->assertSeeInOrder([
            'Alpha Hidden Pending Event',
            'Beta Unlisted Event',
            'Zulu Public Event',
        ]);

    $tableTest = Livewire::withQueryParams(['institution' => $institution->id])
        ->actingAs($user)
        ->test(InstitutionDashboard::class)
        ->set('eventListPage', true)
        ->assertTableColumnExists('title')
        ->assertTableColumnExists('starts_at')
        ->assertTableColumnExists('status')
        ->assertTableColumnExists('speaker_names')
        ->assertTableColumnExists('reference_titles')
        ->assertTableColumnExists('space.name')
        ->assertTableColumnExists('dashboard_registrations_count')
        ->assertTableColumnExists('visibility')
        ->assertTableColumnExists('event_structure')
        ->assertTableColumnExists('is_active')
        ->searchTable('alpha')
        ->filterTable('status', 'pending')
        ->filterTable('visibility', EventVisibility::Private->value)
        ->assertCanSeeTableRecords([$alphaEvent])
        ->assertCanNotSeeTableRecords([$zuluEvent, $betaEvent, $outsideEvent]);

    /** @var InstitutionDashboard $tableInstance */
    $tableInstance = $tableTest->instance();

    expect($tableInstance->getTable()->getColumn('dashboard_registrations_count')?->isToggledHiddenByDefault())->toBeTrue()
        ->and($tableInstance->getTable()->getColumn('visibility')?->isToggledHiddenByDefault())->toBeTrue()
        ->and($tableInstance->getTable()->getColumn('event_structure')?->isToggledHiddenByDefault())->toBeTrue()
        ->and($tableInstance->getTable()->getColumn('is_active')?->isToggledHiddenByDefault())->toBeTrue();

    Livewire::withQueryParams(['institution' => $institution->id])
        ->actingAs($user)
        ->test(InstitutionDashboard::class)
        ->set('eventListPage', true)
        ->sortTable('title', 'asc')
        ->assertCanSeeTableRecords([$alphaEvent, $betaEvent, $zuluEvent], true)
        ->assertCanNotSeeTableRecords([$outsideEvent]);

    Livewire::withQueryParams(['institution' => $institution->id])
        ->actingAs($user)
        ->test(InstitutionDashboard::class)
        ->set('eventListPage', true)
        ->set('eventSort', 'pending_first')
        ->assertCanSeeTableRecords([$alphaEvent, $betaEvent, $zuluEvent], true)
        ->assertCanNotSeeTableRecords([$outsideEvent]);

    Livewire::withQueryParams(['institution' => $institution->id])
        ->actingAs($user)
        ->test(InstitutionDashboard::class)
        ->set('eventListPage', true)
        ->set('eventSort', 'registrations_desc')
        ->assertCanSeeTableRecords([$zuluEvent, $betaEvent, $alphaEvent], true)
        ->assertCanNotSeeTableRecords([$outsideEvent]);
});

it('paginates institution events on the dedicated event list page', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjid Paginate']);

    $user->institutions()->attach($institution->id);

    $events = collect();

    foreach (range(1, 9) as $index) {
        $events->push(Event::factory()->for($institution)->create([
            'title' => sprintf('Paged Institution Event %02d', $index),
            'status' => 'approved',
            'visibility' => 'public',
            'starts_at' => now()->addDays($index),
        ]));
    }

    $pageOne = $this->actingAs($user)
        ->get(route('dashboard.institutions.events', [
            'institution' => $institution->id,
            'event_sort' => 'title_asc',
            'event_per_page' => 8,
        ]));

    $pageOne->assertOk()
        ->assertSee('Paged Institution Event 01')
        ->assertSee('Paged Institution Event 08')
        ->assertDontSee('Paged Institution Event 09');

    $paginationTest = Livewire::withQueryParams([
        'institution' => $institution->id,
        'event_sort' => 'title_asc',
        'event_per_page' => 8,
    ])
        ->actingAs($user)
        ->test(InstitutionDashboard::class);

    /** @var InstitutionDashboard $paginationInstance */
    $paginationInstance = $paginationTest->instance();

    expect($paginationInstance->getTable()->getPaginationMode())->toBe(PaginationMode::Default)
        ->and($paginationInstance->getTable()->hasExtremePaginationLinks())->toBeTrue();

    $pageTwo = $this->actingAs($user)
        ->get(route('dashboard.institutions.events', [
            'institution' => $institution->id,
            'event_sort' => 'title_asc',
            'event_per_page' => 8,
            'institution_events_page' => 2,
        ]));

    $pageTwo->assertOk()
        ->assertSee('Paged Institution Event 09')
        ->assertDontSee('Paged Institution Event 01');
});

it('clearly distinguishes public and internal institution data for members', function () {
    $user = User::factory()->create();
    $attendee = User::factory()->create();

    $institution = Institution::factory()->create(['name' => 'Masjid Pemisahan Scope']);
    $user->institutions()->attach($institution->id);

    $publicEvent = Event::factory()->for($institution)->create([
        'title' => 'Public Institution Event',
        'status' => 'approved',
        'visibility' => 'public',
        'is_active' => true,
        'starts_at' => now()->addDays(2),
    ]);

    $internalEvent = Event::factory()->for($institution)->create([
        'title' => 'Internal Institution Event',
        'status' => 'draft',
        'visibility' => 'private',
        'is_active' => true,
        'starts_at' => now()->addDays(4),
    ]);

    Registration::factory()->for($publicEvent)->for($attendee)->create([
        'name' => 'Public Event Registrant',
        'status' => 'registered',
    ]);

    Registration::factory()->for($internalEvent)->for($attendee)->create([
        'name' => 'Internal Event Registrant',
        'status' => 'registered',
    ]);

    $response = $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->get(route('dashboard.institutions', ['institution' => $institution->id]));

    $response->assertOk()
        ->assertSee('Masjid Pemisahan Scope')
        ->assertSee('Public Institution Event')
        ->assertSee('Internal Institution Event')
        ->assertDontSee('Public Event Registrant')
        ->assertDontSee('Internal Event Registrant');
});

it('lets institution owners and admins add members and manage scoped roles from the dashboard', function () {
    $adminUser = User::factory()->create();
    $memberUser = User::factory()->create([
        'email' => 'new-member@example.test',
    ]);
    $institution = Institution::factory()->create(['name' => 'Masjid Ahli Dashboard']);

    $institution->members()->syncWithoutDetaching([$adminUser->id]);

    app(ScopedMemberRoleSeeder::class)->ensureForInstitution();

    $institutionScope = app(MemberRoleScopes::class)->institution();
    $teamsKey = app(PermissionRegistrar::class)->teamsKey;

    Authz::withScope($institutionScope, function () use ($adminUser): void {
        $adminUser->syncRoles(['admin']);
    }, $adminUser);

    $roleIds = Authz::withScope($institutionScope, fn (): array => Role::query()
        ->where($teamsKey, getPermissionsTeamId())
        ->pluck('id', 'name')
        ->all());

    Livewire::withQueryParams(['institution' => $institution->id])
        ->actingAs($adminUser)
        ->test(InstitutionDashboard::class)
        ->set('newMemberEmail', $memberUser->email)
        ->set('newMemberRoleId', $roleIds['viewer'])
        ->call('addMember')
        ->assertHasNoErrors()
        ->assertSet('newMemberEmail', '')
        ->assertSet('newMemberRoleId', '');

    $response = $this->withSession(['locale' => 'en'])
        ->actingAs($adminUser)
        ->get(route('dashboard.institutions', ['institution' => $institution->id]));

    $response->assertOk()
        ->assertSee('Members & Roles')
        ->assertSee('Manage Invitations');

    expect($institution->fresh()->members()->whereKey($memberUser->id)->exists())->toBeTrue();

    $memberRoleNames = Authz::withScope($institutionScope, fn (): array => $memberUser->fresh()->getRoleNames()->values()->all(), $memberUser);

    expect($memberRoleNames)->toBe(['viewer']);

    Livewire::withQueryParams(['institution' => $institution->id])
        ->actingAs($adminUser)
        ->test(InstitutionDashboard::class)
        ->call('startEditingMemberRoles', $memberUser->id)
        ->set('editingMemberRoleId', $roleIds['editor'])
        ->call('saveMemberRoles')
        ->assertHasNoErrors()
        ->assertSet('editingMemberId', null)
        ->assertSet('editingMemberRoleId', '');

    $updatedRoleNames = Authz::withScope($institutionScope, fn (): array => $memberUser->fresh()->getRoleNames()->values()->all(), $memberUser);

    expect($updatedRoleNames)->toBe(['editor']);

    Livewire::withQueryParams(['institution' => $institution->id])
        ->actingAs($adminUser)
        ->test(InstitutionDashboard::class)
        ->call('removeMember', $memberUser->id)
        ->assertHasNoErrors();

    expect($institution->fresh()->members()->whereKey($memberUser->id)->exists())->toBeFalse()
        ->and(Authz::withScope($institutionScope, fn (): array => $memberUser->fresh()->getRoleNames()->values()->all(), $memberUser))->toBe([]);
});

it('only lets institution owners and admins manage members and never removes owners', function () {
    $ownerUser = User::factory()->create();
    $adminUser = User::factory()->create();
    $viewerUser = User::factory()->create();
    $newMember = User::factory()->create([
        'email' => 'candidate-member@example.test',
    ]);
    $institution = Institution::factory()->create(['name' => 'Masjid Role Rules']);

    $institution->members()->syncWithoutDetaching([
        $ownerUser->id,
        $adminUser->id,
        $viewerUser->id,
    ]);

    app(ScopedMemberRoleSeeder::class)->ensureForInstitution();

    $institutionScope = app(MemberRoleScopes::class)->institution();
    $teamsKey = app(PermissionRegistrar::class)->teamsKey;

    Authz::withScope($institutionScope, function () use ($ownerUser, $adminUser, $viewerUser): void {
        $ownerUser->syncRoles(['owner']);
        $adminUser->syncRoles(['admin']);
        $viewerUser->syncRoles(['viewer']);
    }, $ownerUser);

    $roleIds = Authz::withScope($institutionScope, fn (): array => Role::query()
        ->where($teamsKey, getPermissionsTeamId())
        ->pluck('id', 'name')
        ->all());

    $response = $this->withSession(['locale' => 'en'])
        ->actingAs($viewerUser)
        ->get(route('dashboard.institutions', ['institution' => $institution->id]));

    $response->assertOk()
        ->assertDontSee('Members & Roles')
        ->assertDontSee('Add Member')
        ->assertDontSee('Remove')
        ->assertDontSee('Owner cannot be removed');

    Livewire::withQueryParams(['institution' => $institution->id])
        ->actingAs($viewerUser)
        ->test(InstitutionDashboard::class)
        ->set('newMemberEmail', $newMember->email)
        ->set('newMemberRoleId', $roleIds['viewer'])
        ->call('addMember');

    expect($institution->fresh()->members()->whereKey($newMember->id)->exists())->toBeFalse();

    Livewire::withQueryParams(['institution' => $institution->id])
        ->actingAs($adminUser)
        ->test(InstitutionDashboard::class)
        ->call('startEditingMemberRoles', $ownerUser->id)
        ->assertDispatched('app-toast')
        ->assertSet('editingMemberId', null);

    Livewire::withQueryParams(['institution' => $institution->id])
        ->actingAs($adminUser)
        ->test(InstitutionDashboard::class)
        ->call('removeMember', $ownerUser->id)
        ->assertDispatched('app-toast');

    expect($institution->fresh()->members()->whereKey($ownerUser->id)->exists())->toBeTrue()
        ->and(Authz::withScope($institutionScope, fn (): array => $ownerUser->fresh()->getRoleNames()->values()->all(), $ownerUser))->toBe(['owner']);
});

it('links institution admins to the suggest update page', function () {
    $adminUser = User::factory()->create();
    $institution = Institution::factory()->create([
        'name' => 'Masjid Dashboard Edit',
        'status' => 'verified',
    ]);

    $institution->members()->syncWithoutDetaching([$adminUser->id]);

    app(ScopedMemberRoleSeeder::class)->ensureForInstitution();

    $institutionScope = app(MemberRoleScopes::class)->institution();

    Authz::withScope($institutionScope, function () use ($adminUser): void {
        $adminUser->syncRoles(['admin']);
    }, $adminUser);

    $institutionEditUrl = route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
        'subjectId' => $institution->slug,
    ]);

    $this->withSession(['locale' => 'en'])
        ->actingAs($adminUser)
        ->get(route('dashboard.institutions', ['institution' => $institution->id]))
        ->assertOk()
        ->assertSee('Edit Institution')
        ->assertSee($institutionEditUrl, false);
});

it('does not show the institution edit link to institution viewers', function () {
    $viewerUser = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);

    $institution->members()->syncWithoutDetaching([$viewerUser->id]);

    app(ScopedMemberRoleSeeder::class)->ensureForInstitution();

    $institutionScope = app(MemberRoleScopes::class)->institution();

    Authz::withScope($institutionScope, function () use ($viewerUser): void {
        $viewerUser->syncRoles(['viewer']);
    }, $viewerUser);

    $this->actingAs($viewerUser)
        ->get(route('dashboard.institutions', ['institution' => $institution->id]))
        ->assertOk()
        ->assertDontSee('Edit Institution');
});

it('forbids selecting institutions the user does not belong to', function () {
    $user = User::factory()->create();

    $memberInstitution = Institution::factory()->create();
    $nonMemberInstitution = Institution::factory()->create();

    $user->institutions()->attach($memberInstitution->id);

    $this->actingAs($user)
        ->get(route('dashboard.institutions', ['institution' => $nonMemberInstitution->id]))
        ->assertForbidden();
});

it('does not expose removed institution dashboard legacy urls', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/papan-pemuka/institusi')
        ->assertNotFound();

    $this->actingAs($user)
        ->get('/dashboard/institutions')
        ->assertNotFound();
});

it('does not expose removed advanced schedule urls', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $event = Event::factory()->create([
        'user_id' => $owner->id,
        'submitter_id' => $owner->id,
        'status' => 'draft',
    ]);

    $this->actingAs($owner)
        ->get("/dashboard/events/{$event->id}/schedule")
        ->assertNotFound();

    $this->actingAs($otherUser)
        ->get("/dashboard/events/{$event->id}/schedule")
        ->assertNotFound();
});
