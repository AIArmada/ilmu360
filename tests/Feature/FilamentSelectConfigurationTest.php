<?php

use Filament\Forms\Components\Select;
use Filament\Tables\Filters\SelectFilter;

it('uses custom selects by default throughout the application', function (): void {
    $formSelect = Select::make('status');
    $tableFilter = SelectFilter::make('status');

    expect($formSelect->isNative())->toBeFalse();
    expect($tableFilter->isNative())->toBeFalse();
});
