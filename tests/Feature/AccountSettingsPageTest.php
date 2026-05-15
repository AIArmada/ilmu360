<?php

use App\Livewire\Pages\Dashboard\AccountSettings;
use App\Models\Institution;
use App\Models\NotificationDestination;
use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Services\Notifications\NotificationSettingsManager;
use Filament\Forms\Components\Select as FormSelect;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the account settings page with profile and notifications tabs', function () {
    expect(route('dashboard.account-settings'))->toEndWith('/tetapan-akaun');

    $user = User::factory()->create();

    $response = $this->withSession(['locale' => 'en'])
        ->actingAs($user)
        ->get(route('dashboard.account-settings'));

    $response->assertOk()
        ->assertSee('Account Settings')
        ->assertSee('Profile')
        ->assertSee('Notifications')
        ->assertSee('Profile Details')
        ->assertSee('New Password')
        ->assertSee('Confirm Password')
        ->assertDontSee('API Access')
        ->assertDontSee('Create Token')
        ->assertDontSee('Authorization: Bearer')
        ->assertDontSee('Device Preferences')
        ->assertDontSee('Show country selector on public search pages')
        ->assertDontSee('Changes to email or phone reset their verification status until they are confirmed again.')
        ->assertDontSee('Prayer institution preferences are private and only saved to your account for now.')
        ->assertSee('Prayer Institutions')
        ->assertSee('Daily Prayer Institution')
        ->assertSee('Friday Prayer Institution')
        ->assertSee('Save Account Settings')
        ->assertDontSee('Digest Preferences')
        ->assertDontSee('Save Preferences')
        ->assertSee('fi-fo-phone-input', false);
});

it('places the password fields side by side on desktop', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->assertFormFieldExists('password', function (TextInput $input): bool {
            expect($input->getColumnSpan('default'))->toBe(1);

            return true;
        })
        ->assertFormFieldExists('password_confirmation', function (TextInput $input): bool {
            expect($input->getColumnSpan('default'))->toBe(1);

            return true;
        });
});

it('renders the account settings profile tab in Malay with password labels and no helper callouts', function () {
    $user = User::factory()->create();

    $this->withSession(['locale' => 'ms'])
        ->actingAs($user)
        ->get(route('dashboard.account-settings'))
        ->assertOk()
        ->assertSee('Tetapan Akaun')
        ->assertSee('Kata laluan baharu')
        ->assertSee('Sahkan kata laluan')
        ->assertDontSee('Jika e-mel atau nombor telefon diubah, status pengesahannya akan ditetapkan semula sehingga disahkan semula.')
        ->assertDontSee('Pilihan institusi solat adalah peribadi dan buat masa ini hanya disimpan pada akaun anda.')
        ->assertDontSee('API Access');
});

it('formats timezone options with offsets and timezone identifiers', function () {
    $user = User::factory()->create();

    $options = Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->instance()
        ->timezoneOptions();

    expect($options)->toHaveKey('Asia/Kuala_Lumpur')
        ->and($options['Asia/Kuala_Lumpur'])->toBe('Asia/Kuala_Lumpur (GMT+8)')
        ->and($options)->toHaveKey('UTC')
        ->and($options['UTC'])->toBe('UTC (GMT)');
});

it('renders the notifications tab in Malay without leaking raw translation keys', function () {
    $user = User::factory()->create();

    $response = $this->withSession(['locale' => 'ms'])
        ->actingAs($user)
        ->get(route('dashboard.account-settings', ['tab' => 'notifications']));

    $response->assertOk()
        ->assertSee('Tetapan Akaun')
        ->assertSee('Notifikasi')
        ->assertSee('Notifikasi Push')
        ->assertSee('Ikut tetapan kumpulan')
        ->assertDontSee('notifications.settings.triggers.use_family_defaults')
        ->assertDontSee('notifications.settings.triggers.inherits_family_help')
        ->assertDontSee('notifications.settings.triggers.urgent_override')
        ->assertDontSee('Push Notification');
});

it('updates account settings and resets verification when contact details change', function () {
    Notification::fake();

    $user = User::factory()->create([
        'name' => 'Old Name',
        'email' => 'old@example.test',
        'phone' => '+60111111111',
        'timezone' => 'Asia/Kuala_Lumpur',
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);

    NotificationDestination::factory()->for($user)->create([
        'channel' => 'email',
        'address' => 'old@example.test',
        'external_id' => null,
    ]);
    NotificationDestination::factory()->for($user)->create([
        'channel' => 'whatsapp',
        'address' => '+60111111111',
        'external_id' => null,
    ]);

    session(['user_timezone' => 'Asia/Kuala_Lumpur']);

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->set('formData.name', 'Updated Name')
        ->set('formData.email', 'updated@example.test')
        ->set('formData.phone', '+60122222222')
        ->set('formData.timezone', 'Asia/Jakarta')
        ->call('saveAccountSettings')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toBe('Updated Name')
        ->and($user->email)->toBe('updated@example.test')
        ->and($user->phone)->toBe('+60122222222')
        ->and($user->timezone)->toBe('Asia/Jakarta')
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->phone_verified_at)->toBeNull();

    expect(NotificationDestination::query()
        ->where('user_id', $user->id)
        ->where('channel', 'email')
        ->pluck('address')
        ->all())->toBe(['updated@example.test']);

    $this->assertDatabaseHas('notification_destinations', [
        'user_id' => $user->id,
        'channel' => 'email',
        'address' => 'updated@example.test',
        'status' => 'inactive',
    ]);

    $this->assertDatabaseMissing('notification_destinations', [
        'user_id' => $user->id,
        'channel' => 'email',
        'address' => 'old@example.test',
    ]);

    $this->assertDatabaseMissing('notification_destinations', [
        'user_id' => $user->id,
        'channel' => 'whatsapp',
        'address' => '+60111111111',
    ]);

    $notificationState = app(NotificationSettingsManager::class)->stateFor($user->fresh());

    expect($notificationState['settings']['timezone'])->toBe('Asia/Jakarta');

    Notification::assertSentTo($user->fresh(), VerifyEmailNotification::class);
});

it('updates the account password when the confirmation matches', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->set('formData.password', 'new-password')
        ->set('formData.password_confirmation', 'new-password')
        ->call('saveAccountSettings')
        ->assertHasNoErrors();

    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
});

it('rejects password changes when the confirmation does not match', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->set('formData.password', 'new-password')
        ->set('formData.password_confirmation', 'different-password')
        ->call('saveAccountSettings')
        ->assertHasErrors([
            'formData.password' => 'confirmed',
        ]);
});

it('saves trigger overrides and fallback channels from account settings', function () {
    $user = User::factory()->create([
        'email' => 'member@example.test',
        'email_verified_at' => now(),
        'phone' => '+60128889999',
        'phone_verified_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->set('tab', 'notifications')
        ->set('preferredChannelSlots', ['push', 'email', 'in_app', ''])
        ->set('fallbackChannelSlots', ['whatsapp', 'email', '', ''])
        ->set('notificationTriggersState.event_cancelled.inherits_family', false)
        ->set('notificationTriggersState.event_cancelled.channels', ['whatsapp'])
        ->set('notificationTriggersState.event_cancelled.urgent_override', true)
        ->call('saveNotificationPreferences')
        ->assertHasNoErrors();

    $state = app(NotificationSettingsManager::class)->stateFor($user->fresh());

    expect($state['settings']['preferred_channels'])->toBe(['push', 'email', 'in_app'])
        ->and($state['settings']['fallback_channels'])->toBe(['whatsapp', 'email'])
        ->and($state['triggers']['event_cancelled']['inherits_family'])->toBeFalse()
        ->and($state['triggers']['event_cancelled']['channels'])->toBe(['whatsapp'])
        ->and($state['triggers']['event_cancelled']['urgent_override'])->toBeTrue();
});

it('does not persist unsaved notification changes when saving profile details', function () {
    $user = User::factory()->create([
        'name' => 'Original Name',
        'email' => 'profile@example.test',
        'email_verified_at' => now(),
    ]);

    app(NotificationSettingsManager::class)->save($user, [
        'settings' => [
            'preferred_channels' => ['email'],
            'fallback_channels' => ['email'],
        ],
        'families' => [
            'event_updates' => [
                'enabled' => true,
                'cadence' => 'instant',
                'channels' => ['email'],
            ],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->set('tab', 'notifications')
        ->set('preferredChannelSlots', ['push', '', '', ''])
        ->set('notificationFamiliesState.event_updates.channels', ['push'])
        ->set('formData.name', 'Profile Saved Name')
        ->call('saveAccountSettings')
        ->assertHasNoErrors();

    $user->refresh();
    $state = app(NotificationSettingsManager::class)->stateFor($user);

    expect($user->name)->toBe('Profile Saved Name')
        ->and($state['settings']['preferred_channels'])->toBe(['email'])
        ->and($state['families']['event_updates']['channels'])->toBe(['email']);
});

it('keeps inherited trigger controls aligned with live family changes', function () {
    $user = User::factory()->create([
        'email' => 'sync@example.test',
        'email_verified_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->set('tab', 'notifications')
        ->assertSet('notificationTriggersState.followed_speaker_event.inherits_family', true)
        ->set('notificationFamiliesState.followed_content.cadence', 'weekly')
        ->set('notificationFamiliesState.followed_content.channels', ['push'])
        ->assertSet('notificationTriggersState.followed_speaker_event.cadence', 'weekly')
        ->assertSet('notificationTriggersState.followed_speaker_event.channels', ['push']);
});

it('requires at least one contact method on account settings', function () {
    $user = User::factory()->create([
        'email' => 'member@example.test',
        'phone' => '+60113334444',
    ]);

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->set('formData.email', '')
        ->set('formData.phone', '')
        ->call('saveAccountSettings')
        ->assertHasErrors([
            'formData.email' => 'required_without',
            'formData.phone' => 'required_without',
        ]);
});

it('searches both prayer institution selectors from the institution database', function () {
    $dailyInstitution = Institution::factory()->create([
        'name' => 'Masjid Searchable Daily',
        'status' => 'verified',
        'is_active' => true,
    ]);
    $fridayInstitution = Institution::factory()->create([
        'name' => 'Masjid Searchable Friday',
        'status' => 'verified',
        'is_active' => true,
    ]);
    $inactiveInstitution = Institution::factory()->create([
        'name' => 'Masjid Searchable Inactive',
        'status' => 'verified',
        'is_active' => false,
    ]);
    $pendingInstitution = Institution::factory()->create([
        'name' => 'Masjid Searchable Pending',
        'status' => 'pending',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'email' => 'member@example.test',
        'phone' => '+60113334444',
    ]);

    $component = Livewire::actingAs($user)->test(AccountSettings::class);

    foreach (['daily_prayer_institution_id', 'friday_prayer_institution_id'] as $field) {
        $component->assertFormFieldExists($field, function (FormSelect $select) use ($dailyInstitution, $fridayInstitution, $inactiveInstitution, $pendingInstitution): bool {
            $results = $select->getSearchResults('searchable');

            expect($select->isSearchable())->toBeTrue()
                ->and($select->hasDynamicSearchResults())->toBeTrue()
                ->and($results)->toHaveKey($dailyInstitution->id)
                ->and($results[$dailyInstitution->id])->toBe('Masjid Searchable Daily')
                ->and($results)->toHaveKey($fridayInstitution->id)
                ->and($results[$fridayInstitution->id])->toBe('Masjid Searchable Friday')
                ->and($results)->not->toHaveKey($inactiveInstitution->id)
                ->and($results)->not->toHaveKey($pendingInstitution->id);

            return true;
        });
    }
});

it('saves optional prayer institution preferences and preserves contact verification when contact details are unchanged', function () {
    $dailyInstitution = Institution::factory()->create([
        'name' => 'Masjid Harian',
        'status' => 'verified',
        'is_active' => true,
    ]);
    $fridayInstitution = Institution::factory()->create([
        'name' => 'Masjid Jumaat',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'email' => 'member@example.test',
        'phone' => '+60113334444',
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);

    $component = Livewire::actingAs($user)->test(AccountSettings::class);

    $component
        ->set('formData.daily_prayer_institution_id', $dailyInstitution->id)
        ->set('formData.friday_prayer_institution_id', '')
        ->call('saveAccountSettings')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->daily_prayer_institution_id)->toBe($dailyInstitution->id)
        ->and($user->friday_prayer_institution_id)->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->phone_verified_at)->not->toBeNull();

    $component
        ->set('formData.daily_prayer_institution_id', '')
        ->set('formData.friday_prayer_institution_id', $fridayInstitution->id)
        ->call('saveAccountSettings')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->daily_prayer_institution_id)->toBeNull()
        ->and($user->friday_prayer_institution_id)->toBe($fridayInstitution->id);

    $component
        ->set('formData.daily_prayer_institution_id', $dailyInstitution->id)
        ->set('formData.friday_prayer_institution_id', $dailyInstitution->id)
        ->call('saveAccountSettings')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->daily_prayer_institution_id)->toBe($dailyInstitution->id)
        ->and($user->friday_prayer_institution_id)->toBe($dailyInstitution->id);

    $component
        ->set('formData.daily_prayer_institution_id', $dailyInstitution->id)
        ->set('formData.friday_prayer_institution_id', $fridayInstitution->id)
        ->call('saveAccountSettings')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->daily_prayer_institution_id)->toBe($dailyInstitution->id)
        ->and($user->friday_prayer_institution_id)->toBe($fridayInstitution->id);

    $component
        ->set('formData.daily_prayer_institution_id', '')
        ->set('formData.friday_prayer_institution_id', '')
        ->call('saveAccountSettings')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->daily_prayer_institution_id)->toBeNull()
        ->and($user->friday_prayer_institution_id)->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->phone_verified_at)->not->toBeNull();
});

it('rejects invalid prayer institution ids on account settings', function () {
    $user = User::factory()->create([
        'email' => 'member@example.test',
        'phone' => '+60113334444',
    ]);

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->set('formData.daily_prayer_institution_id', (string) str()->uuid())
        ->call('saveAccountSettings')
        ->assertHasErrors(['formData.daily_prayer_institution_id']);
});

it('rejects inactive or unverified institutions for new prayer preferences', function () {
    $inactiveInstitution = Institution::factory()->create([
        'status' => 'verified',
        'is_active' => false,
    ]);
    $pendingInstitution = Institution::factory()->create([
        'status' => 'pending',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'email' => 'member@example.test',
        'phone' => '+60113334444',
    ]);

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->set('formData.daily_prayer_institution_id', $inactiveInstitution->id)
        ->call('saveAccountSettings')
        ->assertHasErrors(['formData.daily_prayer_institution_id']);

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->set('formData.friday_prayer_institution_id', $pendingInstitution->id)
        ->call('saveAccountSettings')
        ->assertHasErrors(['formData.friday_prayer_institution_id']);
});

it('allows stale saved prayer institution preferences to remain while saving unrelated profile changes', function () {
    $institution = Institution::factory()->create([
        'name' => 'Masjid Lama',
        'status' => 'verified',
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'name' => 'Original Name',
        'email' => 'member@example.test',
        'phone' => '+60113334444',
        'daily_prayer_institution_id' => $institution->id,
        'friday_prayer_institution_id' => $institution->id,
    ]);

    $institution->update([
        'status' => 'pending',
        'is_active' => false,
    ]);

    Livewire::actingAs($user)
        ->test(AccountSettings::class)
        ->assertSet('formData.daily_prayer_institution_id', $institution->id)
        ->assertSet('formData.friday_prayer_institution_id', $institution->id)
        ->set('formData.name', 'Updated Name')
        ->call('saveAccountSettings')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toBe('Updated Name')
        ->and($user->daily_prayer_institution_id)->toBe($institution->id)
        ->and($user->friday_prayer_institution_id)->toBe($institution->id);
});
