<?php

use App\Enums\SpeakerStatus;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the first five eligible featured speakers in curated order', function () {
    $featured = collect([
        'Pilihan Satu',
        'Pilihan Dua',
        'Pilihan Tiga',
        'Pilihan Empat',
        'Pilihan Lima',
        'Pilihan Enam',
    ])->map(fn (string $name, int $index): Person => Person::factory()->create([
        'name' => $name,
        'is_featured' => true,
        'order_column' => ($index + 1) * 10,
    ]));

    $unfeatured = Person::factory()->create(['name' => 'Bukan Pilihan']);
    $inactive = Person::factory()->create([
        'name' => 'Pilihan Tidak Aktif',
        'is_featured' => true,
        'status' => 'inactive',
        'order_column' => 5,
    ]);
    $nonSpeaker = Person::factory()->create([
        'name' => 'Pilihan Bukan Penceramah',
        'is_featured' => true,
        'speaker_status' => SpeakerStatus::Inactive,
        'order_column' => 6,
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSeeInOrder($featured->take(5)->pluck('formatted_name')->all())
        ->assertDontSee($featured[5]->formatted_name)
        ->assertDontSee($unfeatured->formatted_name)
        ->assertDontSee($inactive->formatted_name)
        ->assertDontSee($nonSpeaker->formatted_name);
});

it('shows the homepage speaker empty state when no speaker is featured', function () {
    $speaker = Person::factory()->create(['name' => 'Penceramah Aktif']);

    $this->get('/')
        ->assertOk()
        ->assertSee('Penceramah pilihan akan dipaparkan di sini.')
        ->assertDontSee($speaker->formatted_name);
});

it('removes a speaker from the homepage when the selection is cleared', function () {
    $speaker = Person::factory()->create([
        'name' => 'Penceramah Pilihan',
        'is_featured' => true,
    ]);

    $this->get('/')->assertSee($speaker->formatted_name);

    $speaker->update(['is_featured' => false]);

    $this->get('/')
        ->assertOk()
        ->assertDontSee($speaker->formatted_name)
        ->assertSee('Penceramah pilihan akan dipaparkan di sini.');
});
