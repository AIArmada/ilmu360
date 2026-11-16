<?php

declare(strict_types=1);

use Filament\Forms\Components\Select;
use Filament\Tables\Filters\SelectFilter;

it('uses custom selects by default throughout the application', function (): void {
    $formSelect = Select::make('status');
    $tableFilter = SelectFilter::make('status');

    expect($formSelect->isNative())->toBeFalse()
        ->and($tableFilter->isNative())->toBeFalse();
});
