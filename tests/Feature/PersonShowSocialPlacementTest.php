<?php

use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Contacting\Enums\SocialPlatform;
use App\Models\Person;

it('renders social media inside the consolidated profile panel on person show page', function () {
    $person = Person::factory()->create([
        'status' => 'verified',
        'bio' => [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => 'Biodata test person.',
                ]],
            ]],
        ],
    ]);

    $person->socialProfiles()->create([
        'platform' => 'facebook',
        'url' => 'https://example.com',
        'handle' => 'example',
    ]);

    $this->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertSee('Biodata')
        ->assertSee('Media Sosial')
        ->assertSee('storage/social-media-icons/facebook.svg', false)
        ->assertSeeInOrder(['Biodata', 'Media Sosial']);
});

it('displays public contacts and hides private contacts on person show page', function () {
    $person = Person::factory()->create(['status' => 'verified']);

    $person->contactMethods()->createMany([
        [
            'type' => ContactMethodType::Phone->value,
            'purpose' => ContactPurpose::General->value,
            'value' => '03-12345678',
            'is_public' => true,
        ],
        [
            'type' => ContactMethodType::Email->value,
            'purpose' => ContactPurpose::General->value,
            'value' => 'private@example.com',
            'is_public' => false,
        ],
    ]);

    $this->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertSee('Maklumat Hubungan')
        ->assertSee('href="tel:0312345678"', false)
        ->assertSee('03-12345678')
        ->assertDontSee('private@example.com');
});

it('resolves handle-only social profiles on person show page', function () {
    $person = Person::factory()->create(['status' => 'verified']);

    $person->socialProfiles()->create([
        'platform' => SocialPlatform::Instagram->value,
        'handle' => 'ustazwadiannuar',
    ]);

    $this->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertSee('Media Sosial Rasmi')
        ->assertSee('https://www.instagram.com/ustazwadiannuar', false);
});

it('shows a scrollable biodata region in the hero for long person biodata', function () {
    $person = Person::factory()->create([
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

    $this->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertSee('Biodata')
        ->assertSee(__('Skrol untuk membaca'))
        ->assertSee('h-24 max-h-24', false)
        ->assertDontSee('Ringkasan Profil')
        ->assertSee('[scrollbar-width:thin]', false);
});

it('does not show the long-biodata scroll cue for short person biodata', function () {
    $person = Person::factory()->create([
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

    $this->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertSee('Biodata')
        ->assertDontSee('Skrol untuk membaca');
});

it('hides the biodata section when no biodata is available', function () {
    $person = Person::factory()->create([
        'status' => 'verified',
        'bio' => null,
    ]);

    $this->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertDontSee('Biodata')
        ->assertDontSee('Skrol untuk membaca');
});
