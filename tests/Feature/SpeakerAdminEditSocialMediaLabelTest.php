<?php

use App\Filament\Resources\Speakers\Pages\EditSpeaker;
use App\Filament\Resources\Speakers\SpeakerResource;
use App\Models\Speaker;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

it('loads speaker edit page when speaker has social media row', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $speaker = Speaker::factory()->create();

    $speaker->socialMedia()->create([
        'platform' => 'facebook',
        'username' => 'atiqah',
        'url' => 'https://www.facebook.com/atiqah',
    ]);

    $this->actingAs($administrator)
        ->get(SpeakerResource::getUrl('edit', ['record' => $speaker]))
        ->assertSuccessful();
});

it('saves the speaker edit page when a social media row only has a username', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $speaker = Speaker::factory()->create();
    $speaker->address()->update([
        'country_id' => 132,
    ]);

    $speaker->socialMedia()->create([
        'platform' => 'facebook',
        'username' => 'atiqah',
        'url' => null,
    ]);

    Livewire::actingAs($administrator)
        ->test(EditSpeaker::class, ['record' => $speaker->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($speaker->fresh()->socialMedia()->where('platform', 'facebook')->value('username'))->toBe('atiqah');
});
