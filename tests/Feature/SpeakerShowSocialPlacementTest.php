<?php

use App\Models\Speaker;

it('renders social media section below biodata on speaker show page', function () {
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
        'bio' => [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => 'Biodata test speaker.',
                ]],
            ]],
        ],
    ]);

    $speaker->socialMedia()->create([
        'platform' => 'website',
        'url' => 'https://example.com',
        'username' => 'example',
    ]);

    $this->get(route('speakers.show', $speaker))
        ->assertSuccessful()
        ->assertSee('Biodata')
        ->assertSee('Media Sosial')
        ->assertSeeInOrder(['Biodata', 'Media Sosial']);
});

it('shows a reveal control for long speaker biodata', function () {
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
        'bio' => [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => str_repeat('Biodata panjang penceramah untuk ujian paparan ringkas dan paparan penuh. ', 20),
                ]],
            ]],
        ],
    ]);

    $this->get(route('speakers.show', $speaker))
        ->assertSuccessful()
        ->assertSee('Biodata')
        ->assertSee('Lihat biodata penuh')
        ->assertSee('max-h-80 overflow-hidden', false);
});

it('does not show the biodata reveal control for short speaker biodata', function () {
    $speaker = Speaker::factory()->create([
        'status' => 'verified',
        'bio' => [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => 'Biodata ringkas penceramah.',
                ]],
            ]],
        ],
    ]);

    $this->get(route('speakers.show', $speaker))
        ->assertSuccessful()
        ->assertSee('Biodata')
        ->assertDontSee('Lihat biodata penuh');
});
