<?php

use App\Livewire\Pages\Contributions\SubmitPerson;
use Filament\Forms\Components\TextInput;
use Livewire\Livewire;

it('normalizes social media handles only after the field loses focus', function (): void {
    $component = Livewire::test(SubmitPerson::class)
        ->set('data.social_media', [[
            'platform' => 'facebook',
            'handle' => 'ilmu360',
        ]]);

    $handleField = collect($component->instance()->getForm('form')->getFlatFields())
        ->first(fn (mixed $field): bool => $field instanceof TextInput && $field->getName() === 'handle');

    expect($handleField)
        ->toBeInstanceOf(TextInput::class)
        ->and($handleField?->isLive())->toBeTrue()
        ->and($handleField?->isLiveOnBlur())->toBeTrue();
});
