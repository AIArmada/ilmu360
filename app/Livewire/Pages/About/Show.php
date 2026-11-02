<?php

namespace App\Livewire\Pages\About;

use App\Enums\SpeakerStatus;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
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
            'stats' => $this->stats(),
        ]);
    }

    /**
     * Live proof, never placeholders: real public counts, cached briefly.
     * Empty stays hidden in the view, per the design system's trust rule.
     *
     * @return array{events: int, institutions: int, speakers: int}
     */
    private function stats(): array
    {
        try {
            /** @var array{events: int, institutions: int, speakers: int} $stats */
            $stats = Cache::remember('about.stats', 3600, static function (): array {
                return [
                    'events' => Event::query()->active()->count(),
                    'institutions' => Institution::query()->active()->count(),
                    'speakers' => Person::query()
                        ->whereIn('status', ['verified', 'pending'])
                        ->where('speaker_status', SpeakerStatus::Active->value)
                        ->count(),
                ];
            });

            return $stats;
        } catch (\Throwable) {
            return ['events' => 0, 'institutions' => 0, 'speakers' => 0];
        }
    }
}
