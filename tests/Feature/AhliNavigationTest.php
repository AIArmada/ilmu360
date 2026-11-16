<?php

use App\Filament\Ahli\Resources\Institutions\InstitutionResource;
use App\Filament\Pages\AhliDashboard;
use App\Filament\Resources\Events\EventResource;
use App\Models\Institution;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

it('removes the ahli workspace wrapper and makes events the ahli navigation anchor', function () {
    app()->setLocale('en');

    $user = User::factory()->create();
    $institution = Institution::factory()->create();

    $institution->members()->attach($user->id);

    $this->actingAs($user)
        ->get(AhliDashboard::getUrl(panel: 'ahli'))
        ->assertSuccessful()
        ->assertDontSee('Ahli Workspace');

    Filament::setCurrentPanel('ahli');

    $navigation = app(NavigationManager::class)->get();

    $groupLabels = collect($navigation)
        ->map(fn ($group) => $group->getLabel())
        ->filter()
        ->values()
        ->all();

    expect($groupLabels)->not->toContain('Ahli Workspace');

    $topLevelItems = collect($navigation)
        ->flatMap(function ($group) {
            if (method_exists($group, 'getItems')) {
                return $group->getItems();
            }

            return [$group];
        })
        ->filter(fn ($item) => method_exists($item, 'getLabel'))
        ->values();

    $eventsItem = $topLevelItems->first(
        fn ($item) => $item->getLabel() === 'Events'
    );

    expect($eventsItem)->not->toBeNull()
        ->and(EventResource::getNavigationGroup())->toBe('Events')
        ->and(InstitutionResource::getNavigationGroup())->toBeNull()
        ->and(InstitutionResource::getNavigationParentItem())->toBe('Events')
        ->and($topLevelItems->map(fn ($item) => $item->getLabel())->all())->not->toContain('Ahli Workspace');
});
