<?php

namespace App\Livewire\Pages\About;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    public function render(): View
    {
        /** @var array<string, mixed> $content */
        $content = trans('about');

        return view('livewire.pages.about.show', [
            'content' => is_array($content) ? $content : [],
        ]);
    }
}
