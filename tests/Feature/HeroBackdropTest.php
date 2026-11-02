<?php

declare(strict_types=1);

use App\Models\Event;
use App\Support\Events\HeroBackdrop;

it('resolves a bundled hero backdrop from the site set', function (): void {
    expect(HeroBackdrop::randomUrl())->toBeIn(HeroBackdrop::urls());
});

it('ships every hero backdrop asset', function (): void {
    foreach (HeroBackdrop::urls() as $url) {
        $path = str_replace(asset('/'), public_path('/'), $url);

        expect($path)->toBeFile();
    }
});

it('renders a rotating backdrop on the event header', function (): void {
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDay(),
    ]);

    $this->get(route('events.show', $event))
        ->assertOk()
        ->assertSee('images/events/hero-backgrounds/hero-backdrop-', false);
});
