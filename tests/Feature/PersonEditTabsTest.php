<?php

use App\Filament\Resources\Persons\Schemas\PersonForm;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;

it('does not expose the legacy education tab on the person edit form', function () {
    $tabs = PersonForm::configure(Schema::make())->getComponents()[0];

    expect($tabs)->toBeInstanceOf(Tabs::class);

    $reflection = new ReflectionObject($tabs);

    while (! $reflection->hasProperty('childComponents') && ($parent = $reflection->getParentClass())) {
        $reflection = $parent;
    }

    $childComponents = $reflection->getProperty('childComponents')->getValue($tabs);

    $tabLabels = collect($childComponents['default'] ?? [])
        ->map(fn (object $tab): ?string => method_exists($tab, 'getLabel') ? $tab->getLabel() : null)
        ->all();

    expect($tabLabels)->not->toContain(__('Pendidikan'));
});
